<?php

/** Prints a new APP_KEY value for .env */
echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;
