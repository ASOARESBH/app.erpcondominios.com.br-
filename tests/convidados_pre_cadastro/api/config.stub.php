<?php
function conectar_banco() { $c = new mysqli('127.0.0.1','erp','erp','erptest'); $c->set_charset('utf8mb4'); return $c; }
function sanitizar($c, $v) { return trim((string)$v); }
function registrar_log(...$a) { return true; }
function fechar_conexao($c) { return true; }
