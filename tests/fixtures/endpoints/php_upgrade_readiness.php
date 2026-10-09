<?php

use Core\Endpoint;
use Core\Response;

/** Riesgo detectado en el análisis de migración de PHP: un warning/deprecation
 *  durante el handler hoy termina en 500 porque ErrorHandler no filtra por nivel. */
Endpoint::from(__FILE__)->at('GET /_test/deprecation-warning')
    ->handle(function () {
        trigger_error('aviso de prueba: riesgo de migración de PHP', E_USER_DEPRECATED);
        return Response::ok(['ok' => true]);
    });
