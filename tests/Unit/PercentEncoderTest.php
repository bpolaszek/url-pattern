<?php

declare(strict_types=1);

use BenTools\UrlPattern\Internal\PercentEncoder;

describe('PercentEncoder', function (): void {
    it('always encodes C0 controls, DEL and non-ASCII bytes', function (): void {
        expect(PercentEncoder::encode("\x00\x1F\x7F"))->toBe('%00%1F%7F')
            ->and(PercentEncoder::encode('é'))->toBe('%C3%A9')
            ->and(PercentEncoder::encode('😀'))->toBe('%F0%9F%98%80');
    });

    it('keeps printable ASCII untouched by default', function (): void {
        expect(PercentEncoder::encode(' !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~'))
            ->toBe(' !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~');
    });

    it('encodes the extra code points of the given set', function (string $set, string $input, string $expected): void {
        expect(PercentEncoder::encode($input, $set))->toBe($expected);
    })->with([
        'fragment' => [PercentEncoder::FRAGMENT_SET, ' "<>`\'#?{}', '%20%22%3C%3E%60\'#?{}'],
        'query' => [PercentEncoder::QUERY_SET, ' "#<>\'`?{}', '%20%22%23%3C%3E\'`?{}'],
        'special query' => [PercentEncoder::SPECIAL_QUERY_SET, ' "#<>\'`', '%20%22%23%3C%3E%27`'],
        'path' => [PercentEncoder::PATH_SET, ' "#<>?^`{}/:', '%20%22%23%3C%3E%3F%5E%60%7B%7D/:'],
        'userinfo' => [
            PercentEncoder::USERINFO_SET,
            ' "#<>?^`{}/:;=@[\\]|a',
            '%20%22%23%3C%3E%3F%5E%60%7B%7D%2F%3A%3B%3D%40%5B%5C%5D%7Ca',
        ],
    ]);

    it('uses uppercase hexadecimal digits', function (): void {
        expect(PercentEncoder::encode("\n"))->toBe('%0A');
    });
});
