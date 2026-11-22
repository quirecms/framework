<?php

declare(strict_types=1);

// Must never run: an artifact is data. If it did, the test would find the marker.
file_put_contents(sys_get_temp_dir().'/quire-artifact-php-ran', 'ran');

throw new LogicException('A language artifact\'s PHP file ran.');
