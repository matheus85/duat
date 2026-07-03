<?php

declare(strict_types=1);

// Flaky by design: alternates 15 seconds of health with 15 seconds of
// trouble, plus a pinch of random failures and slowness in the good phase.

$phase = (int) floor(time() / 15) % 2;
$roll = random_int(1, 100);

if ($phase === 1 && $roll <= 80) {
    http_response_code(500);
    echo "the underworld is dark tonight\n";

    exit;
}

if ($phase === 0 && $roll <= 10) {
    http_response_code(500);
    echo "random hiccup\n";

    exit;
}

if ($phase === 0 && $roll > 90) {
    sleep(2);
}

header('Content-Type: application/json');
echo json_encode(['status' => 'sunrise', 'at' => date('H:i:s')]) . "\n";
