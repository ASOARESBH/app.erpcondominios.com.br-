<?php
declare(strict_types=1);

use ERP\Database\MysqliDropInState;
use ERP\Database\PdoMysqliConnection;
use ERP\Database\PdoMysqliStatement;
use ERP\Database\PdoMysqliResult;

function mysqli_report(int $flags): bool
{
    return MysqliDropInState::setReportMode($flags);
}

function mysqli_connect(
    ?string $hostname = null,
    ?string $username = null,
    ?string $password = null,
    ?string $database = null,
    ?int $port = null,
    ?string $socket = null
): mysqli|false {
    $connection = new PdoMysqliConnection($hostname, $username, $password, $database, $port, $socket);
    return $connection->connect_errno === 0 ? $connection : false;
}

function mysqli_connect_error(): ?string
{
    return MysqliDropInState::lastConnectError();
}

function mysqli_close(mysqli $mysql): bool
{
    return $mysql->close();
}

function mysqli_error(mysqli $mysql): string
{
    return $mysql->error;
}

function mysqli_query(mysqli $mysql, string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
{
    return $mysql->query($query, $result_mode);
}

function mysqli_multi_query(mysqli $mysql, string $query): bool
{
    return $mysql->multi_query($query);
}

function mysqli_more_results(mysqli $mysql): bool
{
    return $mysql->more_results();
}

function mysqli_next_result(mysqli $mysql): bool
{
    return $mysql->next_result();
}

function mysqli_store_result(mysqli $mysql): mysqli_result|false
{
    return $mysql->store_result();
}

function mysqli_prepare(mysqli $mysql, string $query): mysqli_stmt|false
{
    return $mysql->prepare($query);
}

function mysqli_set_charset(mysqli $mysql, string $charset): bool
{
    return $mysql->set_charset($charset);
}

function mysqli_character_set_name(mysqli $mysql): string|false
{
    return $mysql->character_set_name();
}

function mysqli_real_escape_string(mysqli $mysql, string $string): string|false
{
    return $mysql->real_escape_string($string);
}

function mysqli_fetch_assoc(mysqli_result $result): array|null
{
    return $result->fetch_assoc();
}

function mysqli_num_rows(mysqli_result $result): int|string
{
    return $result->num_rows;
}

function mysqli_affected_rows(mysqli $mysql): int|string
{
    return $mysql->affected_rows;
}

function mysqli_insert_id(mysqli $mysql): int|string
{
    return $mysql->insert_id;
}

function mysqli_stmt_bind_param(mysqli_stmt $statement, string $types, mixed &...$vars): bool
{
    return $statement->bind_param($types, ...$vars);
}

function mysqli_stmt_execute(mysqli_stmt $statement, ?array $params = null): bool
{
    return $statement->execute($params);
}

function mysqli_stmt_get_result(mysqli_stmt $statement): mysqli_result|false
{
    return $statement->get_result();
}

function mysqli_stmt_close(mysqli_stmt $statement): bool
{
    return $statement->close();
}

function mysqli_stmt_store_result(mysqli_stmt $statement): bool
{
    return $statement->store_result();
}

function mysqli_stmt_send_long_data(mysqli_stmt $statement, int $param_num, string $data): bool
{
    return $statement->send_long_data($param_num, $data);
}

function mysqli_stmt_num_rows(mysqli_stmt $statement): int|string
{
    return $statement->num_rows;
}

function mysqli_stmt_affected_rows(mysqli_stmt $statement): int|string
{
    return $statement->affected_rows;
}
