"""Stealth-браузер: полная маскировка headless Playwright от детекторов ботов."""

from __future__ import annotations

import logging
import random
from typing import Any, Optional

logger = logging.getLogger(__name__)

# Полный stealth-скрипт — патчит все основные точки детекции headless Chrome.
# Внедряется через add_init_script (до любого JS страницы).
STEALTH_SCRIPT = """
() => {
    // 1. navigator.webdriver
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });

    // 2. chrome объект — headless его не имеет
    if (!window.chrome) {
        window.chrome = {
            app: { isInstalled: false, InstallState: { DISABLED: 'disabled', INSTALLED: 'installed', NOT_INSTALLED: 'not_installed' }, RunningState: { CANNOT_RUN: 'cannot_run', READY_TO_RUN: 'ready_to_run', RUNNING: 'running' } },
            runtime: {
                OnInstalledReason: { CHROME_UPDATE: 'chrome_update', INSTALL: 'install', SHARED_MODULE_UPDATE: 'shared_module_update', UPDATE: 'update' },
                OnRestartRequiredReason: { APP_UPDATE: 'app_update', OS_UPDATE: 'os_update', PERIODIC: 'periodic' },
                PlatformArch: { ARM: 'arm', ARM64: 'arm64', MIPS: 'mips', MIPS64: 'mips64', X86_32: 'x86-32', X86_64: 'x86-64' },
                PlatformNaclArch: { ARM: 'arm', MIPS: 'mips', MIPS64: 'mips64', X86_32: 'x86-32', X86_64: 'x86-64' },
                PlatformOs: { ANDROID: 'android', CROS: 'cros', LINUX: 'linux', MAC: 'mac', OPENBSD: 'openbsd', WIN: 'win' },
                RequestUpdateCheckStatus: { NO_UPDATE: 'no_update', THROTTLED: 'throttled', UPDATE_AVAILABLE: 'update_available' },
                connect: function() {},
                sendMessage: function() {},
            },
            csi: function() {},
            loadTimes: function() {},
        };
    }

    // 3. Плагины — headless возвращает пустой массив
    const pluginData = [
        { name: 'Chrome PDF Plugin', filename: 'internal-pdf-viewer', description: 'Portable Document Format' },
        { name: 'Chrome PDF Viewer', filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai', description: '' },
        { name: 'Native Client', filename: 'internal-nacl-plugin', description: '' },
    ];
    const pluginArray = pluginData.map(p => {
        const plugin = Object.create(Plugin.prototype);
        Object.defineProperty(plugin, 'name', { get: () => p.name });
        Object.defineProperty(plugin, 'filename', { get: () => p.filename });
        Object.defineProperty(plugin, 'description', { get: () => p.description });
        Object.defineProperty(plugin, 'length', { get: () => 0 });
        return plugin;
    });
    Object.defineProperty(navigator, 'plugins', {
        get: () => {
            const arr = Object.create(PluginArray.prototype);
            pluginArray.forEach((p, i) => { arr[i] = p; });
            Object.defineProperty(arr, 'length', { get: () => pluginArray.length });
            arr.item = i => pluginArray[i] || null;
            arr.namedItem = name => pluginArray.find(p => p.name === name) || null;
            arr.refresh = () => {};
            return arr;
        }
    });

    // 4. MimeTypes
    Object.defineProperty(navigator, 'mimeTypes', {
        get: () => {
            const arr = Object.create(MimeTypeArray.prototype);
            Object.defineProperty(arr, 'length', { get: () => 0 });
            arr.item = () => null;
            arr.namedItem = () => null;
            return arr;
        }
    });

    // 5. Языки и локаль
    Object.defineProperty(navigator, 'languages', { get: () => ['ru-RU', 'ru', 'en-US', 'en'] });
    Object.defineProperty(navigator, 'language', { get: () => 'ru-RU' });

    // 6. Железо
    Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => 8 });
    Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 });
    Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });

    // 7. Permissions — headless возвращает 'denied' для notifications, реальный браузер 'default'
    const origQuery = window.navigator.permissions.query.bind(navigator.permissions);
    window.navigator.permissions.query = (parameters) => {
        if (parameters.name === 'notifications') {
            return Promise.resolve({ state: Notification.permission, onchange: null });
        }
        return origQuery(parameters);
    };

    // 8. WebGL vendor/renderer — headless выдаёт SwiftShader
    const getParameter = WebGLRenderingContext.prototype.getParameter;
    WebGLRenderingContext.prototype.getParameter = function(parameter) {
        if (parameter === 37445) return 'Intel Inc.';
        if (parameter === 37446) return 'Intel Iris OpenGL Engine';
        return getParameter.call(this, parameter);
    };
    if (window.WebGL2RenderingContext) {
        const getParameter2 = WebGL2RenderingContext.prototype.getParameter;
        WebGL2RenderingContext.prototype.getParameter = function(parameter) {
            if (parameter === 37445) return 'Intel Inc.';
            if (parameter === 37446) return 'Intel Iris OpenGL Engine';
            return getParameter2.call(this, parameter);
        };
    }

    // 9. Canvas fingerprint — добавляем минимальный шум
    const origToDataURL = HTMLCanvasElement.prototype.toDataURL;
    HTMLCanvasElement.prototype.toDataURL = function(type) {
        if (type === 'image/png' && this.width > 16 && this.height > 16) {
            const ctx = this.getContext('2d');
            if (ctx) {
                const imageData = ctx.getImageData(0, 0, 1, 1);
                imageData.data[0] = imageData.data[0] ^ 1;
                ctx.putImageData(imageData, 0, 0);
            }
        }
        return origToDataURL.apply(this, arguments);
    };

    // 10. AudioContext fingerprint
    const origOffline = window.OfflineAudioContext;
    if (origOffline) {
        window.OfflineAudioContext = function(channels, length, sampleRate) {
            const ctx = new origOffline(channels, length, sampleRate);
            const origCreateOscillator = ctx.createOscillator.bind(ctx);
            ctx.createOscillator = function() {
                const osc = origCreateOscillator();
                const origConnect = osc.connect.bind(osc);
                osc.connect = function(dest) { return origConnect(dest); };
                return osc;
            };
            return ctx;
        };
        window.OfflineAudioContext.prototype = origOffline.prototype;
    }

    // 11. outerWidth/outerHeight — headless возвращает 0
    if (window.outerWidth === 0) {
        Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
    }
    if (window.outerHeight === 0) {
        Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight + 88 });
    }

    // 12. screen.colorDepth — headless иногда 24, реальный 24 или 30
    Object.defineProperty(screen, 'colorDepth', { get: () => 24 });
    Object.defineProperty(screen, 'pixelDepth', { get: () => 24 });

    // 13. Убираем $cdc_ и $wdc_ — следы ChromeDriver
    delete window.$cdc_asdjflasutopfhvcZLmcfl_;
    delete window.$wdc_;
}
"""


def random_viewport() -> dict:
    """Случайный viewport из типичных разрешений."""
    viewports = [
        {"width": 1920, "height": 1080},
        {"width": 1440, "height": 900},
        {"width": 1536, "height": 864},
        {"width": 1366, "height": 768},
        {"width": 1280, "height": 800},
    ]
    return random.choice(viewports)


_STEALTH_PROFILES = [
    {
        "ua": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "locale": "ru-RU", "timezone": "Europe/Moscow",
        "sec_ch_ua": '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        "platform": '"Windows"',
    },
    {
        "ua": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36",
        "locale": "ru-RU", "timezone": "Europe/Moscow",
        "sec_ch_ua": '"Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
        "platform": '"Windows"',
    },
    {
        "ua": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "locale": "ru-RU", "timezone": "Europe/Moscow",
        "sec_ch_ua": '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        "platform": '"macOS"',
    },
    {
        "ua": "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0",
        "locale": "ru-RU", "timezone": "Europe/Moscow",
        "sec_ch_ua": "",
        "platform": '"Windows"',
    },
]


async def new_stealth_context(browser: Any, extra_headers: dict | None = None, proxy: str | None = None) -> Any:
    """
    Создаёт новый stealth-контекст браузера со случайным профилем.
    Каждый раз новый контекст = чистые куки/сессия + рандомный UA/timezone.
    """
    vp = random_viewport()
    profile = random.choice(_STEALTH_PROFILES)

    extra_h: dict = {
        "Accept-Language": random.choice(["ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7", "ru-RU,ru;q=0.9,en;q=0.8"]),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Sec-Ch-Ua-Mobile": "?0",
        "Sec-Ch-Ua-Platform": profile["platform"],
    }
    if profile["sec_ch_ua"]:
        extra_h["Sec-Ch-Ua"] = profile["sec_ch_ua"]
    extra_h.update(extra_headers or {})

    context_kwargs: dict = dict(
        user_agent=profile["ua"],
        locale=profile["locale"],
        timezone_id=profile["timezone"],
        viewport=vp,
        screen={"width": vp["width"], "height": vp["height"]},
        color_scheme="light",
        extra_http_headers=extra_h,
    )

    if proxy:
        from urllib.parse import urlparse
        parsed = urlparse(proxy)
        proxy_config: dict = {"server": f"{parsed.scheme}://{parsed.hostname}:{parsed.port}"}
        if parsed.username:
            proxy_config["username"] = parsed.username
        if parsed.password:
            proxy_config["password"] = parsed.password
        context_kwargs["proxy"] = proxy_config

    context = await browser.new_context(**context_kwargs)
    await context.add_init_script(STEALTH_SCRIPT)
    return context
