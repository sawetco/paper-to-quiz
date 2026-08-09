<?php
/**
 * Unit tests for PaperToQuiz\Infrastructure\Crypto.
 *
 * Bootstrapped against the real WordPress install (see tests/bootstrap.php).
 * Uses the Yoast polyfill base class rather than \WP_UnitTestCase so the suite
 * can run without the WordPress PHPUnit test suite.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Infrastructure\Crypto;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class CryptoTest extends TestCase {
    public function test_round_trip_preserves_multibyte_array(): void {
        $crypto = new Crypto();
        $data   = array(
            'email'  => 'ıış@örnek.tr',
            'nested' => array('x' => 1),
        );

        $this->assertSame($data, $crypto->decrypt_array($crypto->encrypt_array($data)));
    }

    public function test_tampered_tag_returns_empty_array(): void {
        $crypto = new Crypto();
        $enc    = $crypto->encrypt_array(array('a' => 1));

        // Flip the last base64 character so the GCM tag/ciphertext no longer matches.
        $bad = substr($enc, 0, -1) . ($enc[-1] === 'A' ? 'B' : 'A');

        $this->assertSame(array(), $crypto->decrypt_array($bad));
    }

    public function test_short_payload_returns_empty_array(): void {
        $crypto = new Crypto();

        $this->assertSame(array(), $crypto->decrypt_array(base64_encode(str_repeat('x', 10))));
    }
}
