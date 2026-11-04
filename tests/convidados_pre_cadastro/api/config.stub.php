<?php
function conectar_banco() { $c = new mysqli('127.0.0.1','erp','erp','erptest'); $c->set_charset('utf8mb4'); return $c; }
