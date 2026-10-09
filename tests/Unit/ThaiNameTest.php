<?php

use Keenthekeen\OAuthHelper\ThaiName\ThaiName;

describe('split', function () {
    it('takes the last word as the last name', function () {
        $name = ThaiName::split('  สมชาย   ใจดี ');

        expect($name->first)->toBe('สมชาย')->and($name->last)->toBe('ใจดี');
    });

    it('keeps a middle name with the first name', function () {
        expect(ThaiName::split('John Michael Smith'))
            ->first->toBe('John Michael')
            ->last->toBe('Smith');
    });

    it('keeps a "ณ" last name whole', function () {
        expect(ThaiName::split('สมศรี ณ อยุธยา'))
            ->first->toBe('สมศรี')
            ->last->toBe('ณ อยุธยา');
    });

    it('handles a single word', function () {
        expect(ThaiName::split('สมชาย'))->first->toBe('สมชาย')->last->toBe('');
    });
});

describe('fromClaims', function () {
    it('reads the configured claims', function () {
        expect(ThaiName::fromClaims(['first_name' => 'สมชาย', 'last_name' => 'ใจดี'])?->full())->toBe('สมชาย ใจดี');
    });

    it('ignores a wrongly encoded name', function () {
        expect(ThaiName::fromClaims(['first_name' => "สม\u{FFFD}าย", 'last_name' => 'ใจดี']))->toBeNull();
    });

    it('ignores a missing part', function () {
        expect(ThaiName::fromClaims(['first_name' => 'สมชาย']))->toBeNull();
    });
});

describe('preferredName', function () {
    it('replaces a different name with the Thai name', function () {
        expect(ThaiName::preferredName('Somchai Jaidee', ['first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'name' => 'Somchai Jaidee']))
            ->toBe('สมชาย ใจดี');
    });

    it('keeps a stored name that contains the Thai name, preserving its title', function () {
        expect(ThaiName::preferredName('ผศ.นพ.สมชาย ใจดี', ['first_name' => 'สมชาย', 'last_name' => 'ใจดี']))->toBeNull();
    });

    it('falls back to the display name without a Thai name', function () {
        expect(ThaiName::preferredName('Old Name', ['name' => 'Somchai Jaidee']))->toBe('Somchai Jaidee')
            ->and(ThaiName::preferredName('Somchai Jaidee', ['name' => 'Somchai Jaidee']))->toBeNull();
    });

    it('keeps the stored name when the Thai name is wrongly encoded', function () {
        expect(ThaiName::preferredName('สมชาย ใจดี', ['first_name' => "\u{FFFD}\u{FFFD}", 'last_name' => 'ใจดี', 'name' => 'Somchai Jaidee']))
            ->toBeNull();
    });
});
