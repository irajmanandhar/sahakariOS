<?php

namespace Tests\Unit;

use App\Support\Preeti;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PreetiTest extends TestCase
{
    /** Column headings and labels used in legacy cooperative passbook workbooks. */
    public static function words(): array
    {
        return [
            ['lgoldt art vftf', 'नियमित बचत खाता'],
            ['P]lR5s art', 'ऐच्छिक बचत'],
            ['cufl8sf] df}Hbft', 'अगाडिको मौज्दात'],
            ['ef}r/ g+=', 'भौचर नं.'],
            ['ljj/0f', 'विवरण'],
            ['Aofh', 'ब्याज'],
            ['s/', 'कर'],
            ['hDdf art', 'जम्मा बचत'],
            ['lems]sf]', 'झिकेको'],
            ['/fv]sf]', 'राखेको'],
            ['ldlt', 'मिति'],
            ["e'QmfgL", 'भुक्तानी'],
            ['JolQmut z]o/ vftf', 'व्यक्तिगत शेयर खाता'],
            ['C0f ;xsf/L ;+:yf', 'ऋण सहकारी संस्था'],
            ['! Jfif]{', '१ वर्षे'],
            ["b'O{ aif]{ dfl;s art", 'दुई बर्षे मासिक बचत'],
            ["d'2tL", 'मुद्दती'],
            ['/]ld6]G;', 'रेमिटेन्स'],
            ['cGo', 'अन्य'],
            ['@)*@', '२०८२'],
        ];
    }

    #[DataProvider('words')]
    public function test_converts_preeti_to_unicode(string $preeti, string $unicode): void
    {
        $this->assertSame($unicode, Preeti::toUnicode($preeti));
    }

    public function test_plain_english_is_kept(): void
    {
        $this->assertSame('Member Renew Charge', Preeti::toUnicodeIfPreeti('Member Renew Charge'));
        $this->assertSame('ब्याज', Preeti::toUnicodeIfPreeti('Aofh'));
    }
}
