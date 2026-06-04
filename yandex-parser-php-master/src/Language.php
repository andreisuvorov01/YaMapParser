<?php

declare(strict_types=1);

namespace YandexParser;

enum Language: string
{
    case Auto = 'auto';
    case Russian = 'ru';
    case English = 'en';
    case Turkish = 'tr';
    case Ukrainian = 'uk';
    case Kazakh = 'kk';
}
