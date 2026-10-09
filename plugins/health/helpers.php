<?php

if (!function_exists('health')) {
    function health(): array
    {
        return Plugins\Health\Health::check();
    }
}
