<?php

// Synthetic key used only by the isolated Laravel bootstrap.
return ['key' => 'base64:' . base64_encode(str_repeat('x', 32))];
