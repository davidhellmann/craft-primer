<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use davidhellmann\primer\decisions\ApplyDecision;

/**
 * Whether saving an entry applies its preset (spec section 5, steps 1 and 2).
 */
class ApplyDecisionTest extends Unit
{
    public function testANewEntryOfASectionGetsItsPreset(): void
    {
        $this->assertTrue($this->decide());
    }

    public function testAnExistingEntryDoesNot(): void
    {
        $this->assertFalse($this->decide(isNew: false));
    }

    public function testAnExistingEntrySwitchedToAnotherEntryTypeGetsThatTypesPreset(): void
    {
        $this->assertTrue($this->decide(isNew: false, typeChanged: true));
    }

    /**
     * @dataProvider copyingSaves
     */
    public function testATypeSwitchInASaveThatOnlyCopiesOrResavesDoesNot(string $flag): void
    {
        $this->assertFalse($this->decide(...['isNew' => false, 'typeChanged' => true, $flag => true]));
    }

    public function testAnEntryWithoutASectionDoesNot(): void
    {
        // Nested entries (Matrix, CKEditor) have an owner instead of a section.
        $this->assertFalse($this->decide(hasSection: false));
    }

    public function testAnEntryWithoutAnEntryTypeDoesNot(): void
    {
        $this->assertFalse($this->decide(hasType: false));
    }

    /**
     * @dataProvider copyingSaves
     */
    public function testSavesThatOnlyCopyOrResaveDoNot(string $flag): void
    {
        $this->assertFalse($this->decide(...[$flag => true]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function copyingSaves(): array
    {
        return [
            'propagating to another site' => ['propagating'],
            'resaving' => ['resaving'],
            'a duplicate' => ['duplicate'],
            'updating from a derivative' => ['updatingFromDerivative'],
            'merging canonical changes' => ['mergingCanonicalChanges'],
            'a revision' => ['revision'],
        ];
    }

    private function decide(
        bool $isNew = true,
        bool $typeChanged = false,
        bool $hasSection = true,
        bool $hasType = true,
        bool $propagating = false,
        bool $resaving = false,
        bool $duplicate = false,
        bool $updatingFromDerivative = false,
        bool $mergingCanonicalChanges = false,
        bool $revision = false,
    ): bool {
        return ApplyDecision::shouldApply(
            isNew: $isNew,
            typeChanged: $typeChanged,
            hasSection: $hasSection,
            hasType: $hasType,
            propagating: $propagating,
            resaving: $resaving,
            duplicate: $duplicate,
            updatingFromDerivative: $updatingFromDerivative,
            mergingCanonicalChanges: $mergingCanonicalChanges,
            revision: $revision,
        );
    }
}
