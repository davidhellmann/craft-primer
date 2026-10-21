<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use davidhellmann\primer\decisions\ApplyDecision;

/**
 * Whether saving an entry applies a preset, and whose (spec section 5, steps 1 and 2).
 */
class ApplyDecisionTest extends Unit
{
    private const TYPE = 7;
    private const OTHER_TYPE = 8;

    public function testANewEntryOfASectionGetsThePresetOfItsType(): void
    {
        $this->assertSame(self::TYPE, $this->decide());
    }

    public function testAnExistingEntryDoesNot(): void
    {
        $this->assertNull($this->decide(isNew: false));
    }

    public function testAnExistingEntrySwitchedToAnotherEntryTypeGetsThatTypesPreset(): void
    {
        $this->assertSame(self::TYPE, $this->decide(isNew: false, typeSet: true, storedTypeId: self::OTHER_TYPE));
    }

    public function testAnExistingEntryWhoseTypeIsSetToTheSameOneDoesNot(): void
    {
        // Craft's editor posts the entry type with every save.
        $this->assertNull($this->decide(isNew: false, typeSet: true, storedTypeId: self::TYPE));
    }

    public function testAStoredTypeDifferentFromAnUnsetOneIsNoSwitch(): void
    {
        $this->assertNull($this->decide(isNew: false, typeSet: false, storedTypeId: self::OTHER_TYPE));
    }

    /**
     * @dataProvider copyingSaves
     */
    public function testATypeSwitchInASaveThatOnlyCopiesOrResavesDoesNot(string $flag): void
    {
        $this->assertNull($this->decide(...[
            'isNew' => false,
            'typeSet' => true,
            'storedTypeId' => self::OTHER_TYPE,
            $flag => true,
        ]));
    }

    public function testAnEntryWithoutASectionDoesNot(): void
    {
        // Nested entries (Matrix, CKEditor) have an owner instead of a section.
        $this->assertNull($this->decide(hasSection: false));
    }

    public function testAnEntryWithoutAnEntryTypeDoesNot(): void
    {
        $this->assertNull($this->decide(typeId: null));
        $this->assertNull($this->decide(isNew: false, typeSet: true, typeId: null, storedTypeId: self::OTHER_TYPE));
    }

    /**
     * @dataProvider copyingSaves
     */
    public function testSavesThatOnlyCopyOrResaveDoNot(string $flag): void
    {
        $this->assertNull($this->decide(...[$flag => true]));
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
        bool $typeSet = false,
        ?int $typeId = self::TYPE,
        ?int $storedTypeId = null,
        bool $hasSection = true,
        bool $propagating = false,
        bool $resaving = false,
        bool $duplicate = false,
        bool $updatingFromDerivative = false,
        bool $mergingCanonicalChanges = false,
        bool $revision = false,
    ): ?int {
        return ApplyDecision::typeToApply(
            isNew: $isNew,
            typeSet: $typeSet,
            typeId: $typeId,
            storedTypeId: $storedTypeId,
            hasSection: $hasSection,
            propagating: $propagating,
            resaving: $resaving,
            duplicate: $duplicate,
            updatingFromDerivative: $updatingFromDerivative,
            mergingCanonicalChanges: $mergingCanonicalChanges,
            revision: $revision,
        );
    }
}
