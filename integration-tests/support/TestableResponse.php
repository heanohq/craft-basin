<?php

namespace heano\basin\tests\craft;

use craft\web\CpScreenResponseBehavior;
use craft\web\TemplateResponseBehavior;
use markhuot\craftpest\behaviors\ExpectableBehavior;
use markhuot\craftpest\behaviors\TestableResponseBehavior;

/**
 * craft-pest's test response, with fixes.
 *
 * Each response starts clean. craft-pest copies the previous response's
 * behaviours onto each new one, which causes two problems:
 *
 * - The shared assertion behaviour rebinds to the newest response, so
 *   `$earlier->assertSee()` would check a later page.
 * - Craft's page-rendering behaviours (a template, a CP screen) carry over,
 *   so a second Control Panel page in one test run renders with the first
 *   page's settings and comes back empty.
 *
 * It also fires the events Yii fires around prepare() when sending a real
 * response, which craft-pest skips (its send() does nothing). The plugin
 * adds spam protection to pages in EVENT_AFTER_PREPARE.
 *
 * Registered in InstallsBasin; HarnessTest checks all three.
 */
class TestableResponse extends \markhuot\craftpest\web\TestableResponse
{
    private const NOT_COPIED = [
        TestableResponseBehavior::class,
        ExpectableBehavior::class,
        TemplateResponseBehavior::class,
        CpScreenResponseBehavior::class,
    ];

    public function prepare(): void
    {
        $this->trigger(self::EVENT_BEFORE_SEND);
        parent::prepare();
        $this->trigger(self::EVENT_AFTER_PREPARE);
    }

    public function attachBehaviors($behaviors)
    {
        foreach ($behaviors as $name => $behavior) {
            foreach (self::NOT_COPIED as $class) {
                if ($behavior instanceof $class) {
                    continue 2;
                }
            }
            $this->attachBehavior($name, $behavior);
        }
    }
}
