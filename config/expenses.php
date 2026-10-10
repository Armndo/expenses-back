<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Balance start
  |--------------------------------------------------------------------------
  |
  | First month (YYYY-MM-DD, first of the month) counted by the running balance
  | ("total money" in GET /data's summary). Nothing before it is added up: an
  | income dated in this month acts as the opening balance.
  |
  */

  "balance_start" => env("BALANCE_START", "2026-09-01"),

];
