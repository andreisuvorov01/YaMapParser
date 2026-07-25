/** Нативное «Поделиться» или копирование ссылки в буфер. */

export type ShareResult = "shared" | "copied" | "failed";

export async function copyOrShare(opts: {
  title?: string;
  text?: string;
  url: string;
}): Promise<ShareResult> {
  if (typeof navigator !== "undefined" && navigator.share) {
    try {
      await navigator.share({
        title: opts.title,
        text: opts.text,
        url: opts.url,
      });
      return "shared";
    } catch (e) {
      if (e instanceof DOMException && e.name === "AbortError") return "failed";
    }
  }

  try {
    await navigator.clipboard.writeText(opts.url);
    return "copied";
  } catch {
    return "failed";
  }
}

export function currentPageUrl(): string {
  if (typeof window === "undefined") return "";
  return window.location.href.split("#")[0] ?? "";
}
