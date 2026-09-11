<?php

declare(strict_types=1);

namespace JMac\Testing\Exceptions;

use JMac\Testing\Diagnostics\Diagnostic;

/**
 * Every concrete subclass holds its own diagnostic fields directly and
 * renders its own getMessage() — there is no separate Diagnostic data class
 * per exception type.
 *
 * Every concrete subclass's public readonly fields are frozen, semver-
 * guaranteed public API: adding a field is a minor-version change; renaming,
 * removing, or retyping one is a major-version change.
 */
abstract class DoubleException extends \RuntimeException implements Diagnostic
{
    public function getDiagnostic(): Diagnostic
    {
        return $this;
    }

    /**
     * Static, not just protected, so the PHPUnit exception variants — which
     * extend AssertionFailedError, not this class — can reuse it too.
     */
    final public static function fabricatedNote(bool $fabricated): string
    {
        if (! $fabricated) {
            return '';
        }

        return "\n\nNote: this double was returned automatically. You will need to configure it "
            .'explicitly if you want it to behave differently.';
    }

    /**
     * Joins $message with fabricatedNote()'s own "\n\n"-prefixed text,
     * since $message can itself already end in "\n" (a call-correlation
     * paragraph). Plain concatenation would leave a stray blank line;
     * unconditional rtrim would wrongly swallow that trailing newline when
     * there's no note to append.
     */
    final public static function appendFabricatedNote(string $message, bool $fabricated): string
    {
        $note = self::fabricatedNote($fabricated);

        return $note === '' ? $message : rtrim($message, "\n").$note;
    }

    /**
     * PHPUnit's CLI renders a failure as `FQCN: getMessage()` with no
     * separator, and these FQCNs are long — the actual message reads as
     * buried under the class name. A leading blank line pushes it onto its
     * own line instead. Static, not just protected, for the same reason as
     * fabricatedNote() above.
     */
    final public static function lead(string $message): string
    {
        return "\n".$message;
    }

    /**
     * Passthru mode's whole premise is "real behavior unless overridden," so
     * an `expects()` mismatch rejecting the call instead of delegating to the
     * real object is easy to read as a bug rather than the deliberate,
     * mode-independent contract it is (see ExpectationCallMismatchFields).
     * Named and shaped after fabricatedNote() above, for the same reason.
     */
    final public static function passthruNote(bool $passthru): string
    {
        if (! $passthru) {
            return '';
        }

        return "\n\nNote: this double is in passthru mode, but using `expects()` means every "
            .'call needs to be configured. Use `allows()` instead if you want '
            .'unmatched calls to run for real.';
    }

    /**
     * Joins $message with passthruNote()'s own "\n\n"-prefixed text, mirroring
     * appendFabricatedNote() above.
     */
    final public static function appendPassthruNote(string $message, bool $passthru): string
    {
        $note = self::passthruNote($passthru);

        return $note === '' ? $message : rtrim($message, "\n").$note;
    }

    /**
     * A best-effort variable name for a code snippet in a message, derived
     * from a double's label (e.g. "SecondLink" -> "secondLink"). Non-identifier
     * characters are stripped since a label isn't always a valid identifier
     * fragment — an intersection-typed fabrication's label looks like
     * "Fillable&Sized".
     */
    final public static function suggestedVariableName(string $label): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $label) ?? '';

        return $sanitized === '' || preg_match('/^[0-9]/', $sanitized) === 1
            ? 'double'
            : lcfirst($sanitized);
    }
}
