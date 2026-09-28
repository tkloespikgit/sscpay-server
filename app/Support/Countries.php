<?php

namespace App\Support;

/**
 * ISO 3166-1 alpha-2 国家/地区列表，收款链接落地页的国家下拉框用。
 *
 * 为什么不用 PHP 的 Locale::getDisplayRegion()：那个函数来自 intl 扩展，
 * 而本项目的运行环境只要求 bcmath/openssl/curl/mbstring/pdo_mysql/zip
 * （见 doc/deployment.md），没有 intl 时会直接 fatal。这份静态表没有依赖。
 *
 * 名称固定英文：落地页面向海外买家，全站固定英文（见 lang/en/checkout.php 注释）。
 *
 * 取值直接落到 orders.shipping_country（char(2)），与 /api/order/create 的
 * shipping_address.country 字段口径一致。
 */
final class Countries
{
    /** Territories without an intl-tel-input phone country (v29). */
    public static function withPhoneCodes(): array
    {
        return array_diff_key(self::all(), array_flip(['AQ', 'BV', 'TF', 'HM', 'PN', 'GS', 'UM']));
    }

    /**
     * @return array<string, string> alpha-2 => 英文名
     */
    public static function all(): array
    {
        return [
            'AF' => 'Afghanistan', 'AX' => 'Åland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria',
            'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla',
            'AQ' => 'Antarctica', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia',
            'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
            'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados',
            'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin',
            'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BQ' => 'Bonaire, Sint Eustatius and Saba',
            'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil',
            'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei Darussalam', 'BG' => 'Bulgaria',
            'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'CV' => 'Cabo Verde', 'KH' => 'Cambodia',
            'CM' => 'Cameroon', 'CA' => 'Canada', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic',
            'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island',
            'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo',
            'CD' => 'Congo (Democratic Republic)', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica',
            'CI' => "Côte d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CW' => 'Curaçao',
            'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark', 'DJ' => 'Djibouti',
            'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt',
            'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia',
            'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FK' => 'Falkland Islands', 'FO' => 'Faroe Islands',
            'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana',
            'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'GA' => 'Gabon',
            'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana',
            'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada',
            'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey',
            'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti',
            'HM' => 'Heard Island and McDonald Islands', 'VA' => 'Holy See', 'HN' => 'Honduras',
            'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India',
            'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland',
            'IM' => 'Isle of Man', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica',
            'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan',
            'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'Korea (North)', 'KR' => 'Korea (South)',
            'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia',
            'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya',
            'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao',
            'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives',
            'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique',
            'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico',
            'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia',
            'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique',
            'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal',
            'NL' => 'Netherlands', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua',
            'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island',
            'MK' => 'North Macedonia', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway',
            'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine',
            'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru',
            'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal',
            'PR' => 'Puerto Rico', 'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania',
            'RU' => 'Russian Federation', 'RW' => 'Rwanda', 'BL' => 'Saint Barthélemy',
            'SH' => 'Saint Helena', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia',
            'MF' => 'Saint Martin (French part)', 'PM' => 'Saint Pierre and Miquelon',
            'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino',
            'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal',
            'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore',
            'SX' => 'Sint Maarten (Dutch part)', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
            'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa',
            'GS' => 'South Georgia and the South Sandwich Islands', 'SS' => 'South Sudan',
            'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname',
            'SJ' => 'Svalbard and Jan Mayen', 'SE' => 'Sweden', 'CH' => 'Switzerland',
            'SY' => 'Syrian Arab Republic', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan',
            'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo',
            'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia',
            'TR' => 'Türkiye', 'TM' => 'Turkmenistan', 'TC' => 'Turks and Caicos Islands',
            'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates',
            'GB' => 'United Kingdom', 'US' => 'United States', 'UM' => 'United States Minor Outlying Islands',
            'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela',
            'VN' => 'Viet Nam', 'VG' => 'Virgin Islands (British)', 'VI' => 'Virgin Islands (U.S.)',
            'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen',
            'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        ];
    }

    public static function isValid(?string $alpha2): bool
    {
        return $alpha2 !== null && array_key_exists(strtoupper($alpha2), self::all());
    }

    public static function name(?string $alpha2): ?string
    {
        return self::all()[strtoupper((string) $alpha2)] ?? null;
    }
}
