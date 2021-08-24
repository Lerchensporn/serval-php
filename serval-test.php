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
	#[ServalUInt16]
	public int $int = 999;

	#[ServalItemType(Anchor::class)]
	public array $anchors;

	#[ServalItemType(string::class)]
	public array $strings;

	#[ServalIgnore]
	public array $products;

	public Nulls $nulls;
}

function string2bits($string)
{
    $bits = '';
    for($i = 0 ; $i < strlen($string); ++$i){
        $byte = decbin(ord($string[$i]));
        $bits .= substr('00000000', 0, 8 - strlen($byte)) . $byte . ' ';
    }
    return $bits;
}

class Nulls
{
	public ?bool $null1 = true;
	#[ServalUInt8]
	public ?int $null2 = 0;
	#[ServalUInt16]
	public ?int $null3 = 1;
	#[ServalUInt16]
	public ?int $null4 = 0;
	#[ServalUInt8]
	public ?int $null5 = 1;
	#[ServalUInt16]
	public ?int $null6 = 0;
	#[ServalItemType(bool::class)]
	public array $bools = [true, true, false, false];
	#[ServalUInt8]
	#[ServalItemType(int::class)]
	public array $ints = [1, 2, 3, 4];
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
	$serval = serval($testdata, get_class($testdata));
	#print(string2bits($serval) . "\n");
	$testdata2 = unserval($serval, get_class($testdata));
	print("Validity test: ");
	$print_testdata = print_r($testdata, true);
	$print_testdata2 = print_r($testdata2, true);
	if ($print_testdata === $print_testdata2) {
		print("ok\n");
	}
	else {
		print("failed\n");
		print($print_testdata);
		print($print_testdata2);
		exit;
	}
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
		unserval($ser, get_class($testdata));
#		unserialize(gzinflate($ser));
	}
	print('Performance: ' . round($i / (microtime(true) - $time)) . ' calls per second, '
		. round($i * strlen($ser) / (microtime(true) - $time) / 1024 / 1024 * 8, 2) . " MBit/s\n");
}
