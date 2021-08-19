<?php

require_once(__DIR__ . '/serval.php');

$testdata = new TestData;
$testdata->title = 'Titel';
$testdata->url = 'https://example.org';
$testdata->anchors = [new Anchor('eins', 1), new Anchor('zwei', 2), new Anchor('drei', null), new Anchor('vier', 4)];
$testdata->strings = ['Lirum', 'larum', 'Löffelstiel'];
$testdata->nulls = new Nulls;

class TestData
{
	public string $title;
	public string $url;
	public int|null $int = 999;

	#[ServalItemType(Anchor::class)]
	public array $anchors;

	#[ServalItemType(string::class)]
	public array $strings;

	#[ServalIgnore]
	public array $products;

	public Nulls $nulls;
}

class Nulls
{
	public ?int $null1 = null;
	public ?int $null2 = 1;
	public ?int $null3 = 1;
	public ?int $null4 = null;
	public ?int $null5 = 1;
	public ?int $null6 = null;
	public ?int $null7 = 1;
	public ?int $null8 = null;
	public ?int $null9 = 1;
	public ?int $null10 = null;
}

class Anchor
{
	function __construct(public string $url, public ?int $bla) { }
}

test_validity();
test_compression();
test_performance();

function test_validity()
{
	global $testdata;
	$testdata2 = unserval(serval($testdata), TestData::class);
	print("Validity test: ");
	print(print_r($testdata, true) === print_r($testdata2, true) ? 'ok' : 'failed');
	print("\n");
}

function test_compression()
{
	global $testdata;
	$ser = serval($testdata);
	print('Length of serval: ' . strlen($ser)
		. ', json_encode: ' . strlen(json_encode($testdata))
		. ', serialize: ' . strlen(serialize($testdata))) . "\n";
	print('Length of serval+gzip: ' . strlen(gzdeflate($ser))
		. ', json_encode+gzip: ' . strlen(gzdeflate(json_encode($testdata)))
		. ', serialize+gzip: ' . strlen(gzdeflate(serialize($testdata))) . "\n");
}

function test_performance()
{
	global $testdata;
	$time = microtime(true);
	$ser = serval($testdata);
#	$ser = gzdeflate(serialize($testdata));
	for ($i = 0; $i < 100000; ++$i) {
		unserval($ser, TestData::class);
#		unserialize(gzinflate($ser));
	}
	print('Performance: ' . round($i / (microtime(true) - $time)) . ' calls per second, '
		. round($i * strlen($ser) / (microtime(true) - $time) / 1024 / 1024 * 8, 2) . " MBit/s\n");
}
