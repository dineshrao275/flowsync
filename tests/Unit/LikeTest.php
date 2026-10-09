<?php

namespace Tests\Unit;

use App\Support\Like;
use PHPUnit\Framework\TestCase;

class LikeTest extends TestCase
{
    public function test_wildcards_in_the_term_are_matched_literally(): void
    {
        $this->assertSame('%100\\%%', Like::contains('100%'));
        $this->assertSame('%a\\_b%', Like::contains('a_b'));
        $this->assertSame('%c:\\\\dir%', Like::contains('c:\\dir'));
        $this->assertSame('%plain%', Like::contains('plain'));
    }
}
