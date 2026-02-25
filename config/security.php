<?php

return [
  'encryption_key' => env('ENCRYPTION_KEY', ''),
  'bcrypt_rounds'  => (int) env('BCRYPT_ROUNDS', 12),
];
