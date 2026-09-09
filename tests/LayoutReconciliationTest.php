<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use ConfigurationDouble;
use FeedDaoDouble;
use FreshRSS_Context;
use FreshVibesViewExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tryallthethings\FreshVibes\Models\LayoutSchema;

/**
 * Controller-level tests for custom-mode layout reconciliation (FV-003, FV-004).
 *
 * `LayoutSchema` is unit-tested on its own, but the defect lived in the wiring: `getLayout()`
 * simply did not place feeds subscribed to after the layout was written. The client renders an
 * unplaced feed into the first tab anyway, so the displayed tab held one more feed than the stored
 * tab was allowed to hold and every within-tab drag was rejected.
 *
 * These run the real `getLayout()` against framework doubles. The controller is built without its
 * constructor, because that one wires up a Minz view the layout path never touches.
 */
final class LayoutReconciliationTest extends TestCase {

	private const CONTROLLER = 'FreshExtension_freshvibes_Controller';

	private ConfigurationDouble $conf;

	#[\Override]
	protected function setUp(): void {
		if (!class_exists(self::CONTROLLER, false)) {
			require_once __DIR__ . '/../Controllers/freshvibesController.php';
		}
		$this->conf = new ConfigurationDouble();
		FreshRSS_Context::$conf = $this->conf;
	}

	#[\Override]
	protected function tearDown(): void {
		FreshRSS_Context::$conf = null;
		FeedDaoDouble::$feeds = [];
	}

	/**
	 * Run the real `getLayout()` for a user with `$subscribed` feeds and the given stored layout.
	 *
	 * @param list<array<string,mixed>>|null $stored
	 * @return list<array<string,mixed>>
	 */
	private function getLayout(int $subscribed, ?array $stored, string $position = 'bottom'): array {
		// range(1, 0) counts down, so an account with no feeds needs the explicit empty list.
		FeedDaoDouble::subscribe($subscribed > 0 ? range(1, $subscribed) : []);

		$this->conf->data = [
			FreshVibesViewExtension::MODE_CONFIG_KEY => 'custom',
			FreshVibesViewExtension::NEW_FEED_POSITION_CONFIG_KEY => $position,
		];
		if ($stored !== null) {
			$this->conf->data[FreshVibesViewExtension::LAYOUT_CONFIG_KEY] = $stored;
		}
		$this->conf->saveCalls = 0;

		$reflection = new ReflectionClass(self::CONTROLLER);
		$method = $reflection->getMethod('getLayout');
		$method->setAccessible(true);

		/** @var list<array<string,mixed>> $layout */
		$layout = $method->invoke($reflection->newInstanceWithoutConstructor());
		return $layout;
	}

	/**
	 * A tab holding the given feeds in one column.
	 *
	 * @param list<int> $feeds
	 * @return array<string,mixed>
	 */
	private static function storedTab(string $id, array $feeds): array {
		return [
			'id' => $id, 'name' => $id, 'icon' => '', 'icon_color' => '',
			'num_columns' => 1, 'columns' => ['col1' => $feeds],
		];
	}

	/**
	 * Assert that a within-tab drag of every tab would be accepted, which is what the defect broke.
	 *
	 * @param list<array<string,mixed>> $layout
	 */
	private static function assertEveryTabIsDraggable(array $layout, int $subscribed): void {
		foreach ($layout as $tab) {
			self::assertNotNull(
				LayoutSchema::validateColumns(
					$tab['columns'],
					range(1, $subscribed),
					count($tab['columns']),
					LayoutSchema::tabSize($layout, $tab['id'])
				),
				'A drag inside tab ' . $tab['id'] . ' must be acceptable.'
			);
		}
	}

	// ---------------------------------------------------------------- the reported scenario

	/** @return array<string,array{string}> */
	public static function positionProvider(): array {
		return ['bottom' => ['bottom'], 'top' => ['top']];
	}

	#[DataProvider('positionProvider')]
	public function testASubscriptionAddedToAFullTabIsPlacedAndStaysDraggable(string $position): void {
		$subscribed = LayoutSchema::MAX_FEEDS_PER_TAB + 1;
		$stored = [self::storedTab('full', range(1, LayoutSchema::MAX_FEEDS_PER_TAB))];

		$layout = $this->getLayout($subscribed, $stored, $position);

		self::assertCount($subscribed, LayoutSchema::placedFeedIds($layout));
		self::assertGreaterThan(1, count($layout), 'the overflow needs a tab of its own');
		self::assertLessThanOrEqual(LayoutSchema::MAX_FEEDS_PER_TAB, LayoutSchema::largestTabSize($layout));
		self::assertEveryTabIsDraggable($layout, $subscribed);
		self::assertSame(1, $this->conf->saveCalls);
	}

	#[DataProvider('positionProvider')]
	public function testOrdinaryNewSubscriptionsJoinTheExistingTab(string $position): void {
		$layout = $this->getLayout(25, [self::storedTab('main', range(1, 20))], $position);

		self::assertCount(1, $layout);
		self::assertCount(25, LayoutSchema::placedFeedIds($layout));
		self::assertEveryTabIsDraggable($layout, 25);
	}

	public function testTheNewFeedPositionPreferenceIsHonoured(): void {
		$top = $this->getLayout(4, [self::storedTab('main', [1, 2, 3])], 'top');
		self::assertSame([4, 1, 2, 3], $top[0]['columns']['col1']);

		$bottom = $this->getLayout(4, [self::storedTab('main', [1, 2, 3])], 'bottom');
		self::assertSame([1, 2, 3, 4], $bottom[0]['columns']['col1']);
	}

	public function testManyNewSubscriptionsSpreadOverSeveralTabs(): void {
		$subscribed = LayoutSchema::MAX_FEEDS_PER_TAB * 3;
		$stored = [self::storedTab('main', range(1, LayoutSchema::MAX_FEEDS_PER_TAB))];

		$layout = $this->getLayout($subscribed, $stored);

		self::assertCount(3, $layout);
		self::assertCount($subscribed, LayoutSchema::placedFeedIds($layout));
		self::assertEveryTabIsDraggable($layout, $subscribed);
	}

	public function testGeneratedTabIdsAreDistinct(): void {
		$subscribed = LayoutSchema::MAX_FEEDS_PER_TAB * 3;
		$layout = $this->getLayout($subscribed, [self::storedTab('main', range(1, LayoutSchema::MAX_FEEDS_PER_TAB))]);

		$ids = array_column($layout, 'id');
		self::assertSame($ids, array_unique($ids));
		foreach ($ids as $id) {
			self::assertLessThanOrEqual(LayoutSchema::MAX_TAB_ID_LENGTH, strlen($id));
		}
	}

	// ---------------------------------------------------------------- unchanged reads

	/** FV-004: a settled account must not write on a plain read. */
	public function testASettledLayoutIsNotRewritten(): void {
		$layout = $this->getLayout(20, [self::storedTab('main', range(1, 20))]);

		self::assertSame(0, $this->conf->saveCalls);
		self::assertCount(20, LayoutSchema::placedFeedIds($layout));
	}

	public function testUnsubscribedFeedsDoNotTriggerAWrite(): void {
		// The stored layout still lists a feed the user has since removed.
		$this->getLayout(3, [self::storedTab('main', [1, 2, 3, 99])]);

		self::assertSame(0, $this->conf->saveCalls);
	}

	// ---------------------------------------------------------------- first run

	public function testAnAccountWithNoLayoutGetsOneCoveringEveryFeed(): void {
		$layout = $this->getLayout(30, null);

		self::assertCount(1, $layout);
		self::assertCount(30, LayoutSchema::placedFeedIds($layout));
		self::assertSame(1, $this->conf->saveCalls);
	}

	public function testAFirstLayoutAboveThePerTabCeilingIsSplit(): void {
		$subscribed = LayoutSchema::MAX_FEEDS_PER_TAB + 1;

		$layout = $this->getLayout($subscribed, null);

		self::assertCount(2, $layout);
		self::assertCount($subscribed, LayoutSchema::placedFeedIds($layout));
		self::assertTrue(LayoutSchema::withinLimits($layout));
		self::assertEveryTabIsDraggable($layout, $subscribed);
	}

	public function testAnAccountWithNoFeedsStillGetsATab(): void {
		$layout = $this->getLayout(0, null);

		self::assertCount(1, $layout);
		self::assertSame([], LayoutSchema::placedFeedIds($layout));
	}
}
