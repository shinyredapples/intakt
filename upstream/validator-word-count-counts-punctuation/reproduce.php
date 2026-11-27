<?php

declare(strict_types=1);

use Symfony\Component\Validator\Constraints\WordCount;
use Symfony\Component\Validator\Validation;

$validator = Validation::createValidator();
$punctuation = ['. . .', '- - -', '! ? !'];
$counted = [];

foreach ($punctuation as $value) {
    $violations = $validator->validate($value, new WordCount(min: 3));
    echo json_encode($value), ' => ', 0 === count($violations) ? 'accepted as 3 words' : 'refused', PHP_EOL;

    if (0 === count($violations)) {
        $counted[] = $value;
    }
}

// A punctuation mark is no word, so a value of three marks holds no words and min: 3 must refuse it.
if ([] !== $counted) {
    echo 'punctuation marks count as words', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
