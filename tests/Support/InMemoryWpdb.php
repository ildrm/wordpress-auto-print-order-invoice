<?php

namespace WCInvoicePrinter\Tests\Support;

/** Stateful double for the job repository's SQL; a real database is not emulated. */
final class InMemoryWpdb {
	public string $prefix = 'wp_';
	public array $rows = array();
	public array $queries = array();
	public array $updates = array();
	public bool $fail_insert = false;
	public bool $fail_update = false;
	public mixed $before_insert = null;
	public mixed $before_update = null;
	private int $total = 0;

	public function insert( string $table, array $data, array $formats = array() ): int|false {
		if ( is_callable( $this->before_insert ) ) {
			$callback = $this->before_insert;
			$this->before_insert = null;
			$callback( $this, $data );
		}
		if ( $this->fail_insert ) { return false; }
		foreach ( $this->rows as $row ) {
			if ( $row['idempotency_key'] === $data['idempotency_key'] ) { return false; }
		}
		$id = $this->rows ? max( array_keys( $this->rows ) ) + 1 : 1;
		$this->rows[ $id ] = array_merge( array( 'id' => $id, 'attempt_count' => 0, 'action_id' => null, 'external_job_id' => null, 'error_code' => null, 'error_message' => null, 'started_at' => null, 'completed_at' => null ), $data );
		return 1;
	}

	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|false {
		$this->updates[] = compact( 'table', 'data', 'where' );
		if ( is_callable( $this->before_update ) ) {
			$callback = $this->before_update;
			$this->before_update = null;
			$callback( $this, $data, $where );
		}
		if ( $this->fail_update ) { return false; }
		$count = 0;
		foreach ( $this->rows as &$row ) {
			foreach ( $where as $key => $value ) {
				if ( (string) $row[ $key ] !== (string) $value ) { continue 2; }
			}
			$row = array_merge( $row, $data );
			++$count;
		}
		return $count;
	}

	public function prepare( string $sql, mixed ...$values ): string {
		if ( isset( $values[0] ) && is_array( $values[0] ) ) { $values = $values[0]; }
		$index = 0;
		return preg_replace_callback( '/%[ds]/', static function ( array $match ) use ( &$index, $values ): string {
			$value = $values[ $index++ ];
			return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
		}, $sql );
	}

	public function get_row( string $sql, mixed $format = null ): ?array { return $this->get_results( $sql, $format )[0] ?? null; }

	public function get_results( string $sql, mixed $format = null ): array {
		$this->queries[] = $sql;
		$rows = array_values( array_filter( $this->rows, fn( $row ) => $this->matches( $row, $sql ) ) );
		$this->total = count( $rows );
		usort( $rows, static fn( $a, $b ) => str_contains( $sql, 'id ASC' ) ? $a['id'] <=> $b['id'] : $b['id'] <=> $a['id'] );
		preg_match( '/LIMIT (\d+)(?: OFFSET (\d+))?/', $sql, $limit );
		return $limit ? array_slice( $rows, (int) ( $limit[2] ?? 0 ), (int) $limit[1] ) : $rows;
	}

	public function get_var( string $sql ): int { return $this->total; }

	public function query( string $sql ): int {
		$this->queries[] = $sql;
		preg_match( '/ SET (.*?) WHERE (.*)$/', $sql, $parts );
		if ( ! $parts ) { throw new \LogicException( 'Unsupported SQL: ' . $sql ); }
		$count = 0;
		foreach ( $this->rows as &$row ) {
			if ( ! $this->matches( $row, $parts[2] ) ) { continue; }
			preg_match_all( "/([a-z_]+) = (NULL|'(?:[^'\\\\]|\\\\.)*'|[0-9]+)/", $parts[1], $assignments, PREG_SET_ORDER );
			foreach ( $assignments as $assignment ) {
				$row[ $assignment[1] ] = 'NULL' === $assignment[2] ? null : ( is_numeric( $assignment[2] ) ? (int) $assignment[2] : stripslashes( trim( $assignment[2], "'" ) ) );
			}
			if ( str_contains( $parts[1], 'attempt_count = attempt_count + 1' ) ) { ++$row['attempt_count']; }
			++$count;
		}
		return $count;
	}

	private function matches( array $row, string $sql ): bool {
		$where = str_contains( $sql, ' WHERE ' ) ? substr( $sql, strpos( $sql, ' WHERE ' ) + 7 ) : $sql;
		preg_match_all( "/\\b(id|order_id|action_id|status|trigger_type|provider_id|idempotency_key) = ('(?:[^'\\\\]|\\\\.)*'|[0-9]+)/", $where, $conditions, PREG_SET_ORDER );
		foreach ( $conditions as $condition ) {
			if ( (string) ( $row[ $condition[1] ] ?? '' ) !== stripslashes( trim( $condition[2], "'" ) ) ) { return false; }
		}
		if ( preg_match( '/id > (\d+)/', $where, $minimum ) && $row['id'] <= (int) $minimum[1] ) { return false; }
		if ( preg_match( '/attempt_count < (\d+)/', $where, $attempt ) && $row['attempt_count'] >= (int) $attempt[1] ) { return false; }
		if ( preg_match( '/status IN \((.*?)\)/', $where, $statuses ) ) {
			$allowed = array_map( static fn( $status ) => trim( $status, " '\t" ), explode( ',', $statuses[1] ) );
			if ( ! in_array( $row['status'], $allowed, true ) ) { return false; }
		}
		if ( preg_match( '/error_code IN \((.*?)\)/', $where, $codes ) ) {
			$allowed = array_map( static fn( $code ) => trim( $code, " '\t" ), explode( ',', $codes[1] ) );
			if ( ! in_array( $row['error_code'], $allowed, true ) ) { return false; }
		}
		return true;
	}
}
