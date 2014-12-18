<?php

namespace LessQL;

/**
 * Represents a row of an SQL table (associative)
 */
class Row implements \ArrayAccess, \IteratorAggregate, \JsonSerializable {

	/**
	 * Constructor
	 * Use $db->createRow() instead
	 */
	function __construct( $db, $name, $properties = array(), $result = null ) {

		$this->_db = $db;
		$this->_result = $result;
		$this->_table = $this->_db->getAlias( $name );

		$this->setData( $properties );

	}

	/**
	 * Get a property
	 */
	function &__get( $column ) {

		if ( !isset( $this->_properties[ $column ] ) ) {

			$null = null;
			return $null;

		}

		return $this->_properties[ $column ];

	}

	/**
	 * Set a property
	 */
	function __set( $column, $value ) {

		if ( isset( $this->_properties[ $column ] ) && $this->_properties[ $column ] === $value ) {

			return;

		}

		// convert arrays to Rows or list of Rows

		if ( is_array( $value ) ) {

			$name = preg_replace( '/List$/', '', $column );
			$table = $this->getDatabase()->getAlias( $name );

			if ( $name === $column ) { // row

				$value = $this->getDatabase()->createRow( $table, $value );

			} else { // list

				foreach ( $value as $i => $v ) {

					$value[ $i ] = $this->getDatabase()->createRow( $table, $v );

				}

			}

		}

		$this->_properties[ $column ] = $value;
		$this->_modified[ $column ] = $value;

	}

	/**
	 * Check if property is not null
	 */
	function __isset( $column ) {

		return isset( $this->_properties[ $column ] );

	}

	/**
	 * Remove a property from this row
	 * Property will be ignored when saved, different to setting to null
	 */
	function __unset( $column ) {

		unset( $this->_properties[ $column ] );
		unset( $this->_modified[ $column ] );

	}

	/**
	 * Get referenced row(s) by name. Suffix "List" gets many rows using
	 * a back reference.
	 */
	function __call( $name, $args ) {

		array_unshift( $args, $name );

		return call_user_func_array( array( $this, 'referenced' ), $args );

	}

	/**
	 * Get referenced row(s) by name. Suffix "List" gets many rows using
	 * a back reference.
	 */
	function referenced( $name, $where = null, $params = array() ) {

		$result = $this->getDatabase()->createResult( $this, $name );

		if ( $where !== null ) $result->where( $where, $params );

		return $result;

	}

	/**
	 * Get the id
	 */
	function getId() {

		$primary = $this->getDatabase()->getPrimary( $this->getTable() );

		if ( is_array( $primary ) ) {

			$id = array();

			foreach ( $primary as $column ) {

				if ( !isset( $this[ $column ] ) ) return null;

				$id[ $column ] = $this[ $column ];

			}

			return $id;

		}

		return $this[ $primary ];

	}

	/**
	 * Get the row data
	 */
	function getData() {

		$data = array();

		foreach ( $this->_properties as $column => $value ) {

			if ( $value instanceof Row || is_array( $value ) ) {

				continue;

			}

			$data[ $column ] = $value;

		}

		return $data;

	}

	/**
	 * Set row data (extends the row)
	 */
	function setData( $data ) {

		foreach ( $data as $column => $value ) {

			$this->__set( $column, $value );

		}

		return $this;

	}

	/**
	 * Get the original id
	 */
	function getOriginalId() {

		return $this->_originalId;

	}

	/**
	 * Get modified data
	 */
	function getModified() {

		$modified = array();

		foreach ( $this->_modified as $column => $value ) {

			if ( $value instanceof Row || is_array( $value ) ) {

				continue;

			}

			$modified[ $column ] = $value;

		}

		return $modified;

	}

	/**
	 * Save this row
	 * Also saves associated rows if $recursive is true (default)
	 */
	function save( $recursive = true ) {

		if ( !$recursive ) { // just save the row

			$this->updateReferences();

			if ( !$this->isClean() ) {

				$primary = $this->getDatabase()->getPrimary( $this->getTable() );

				if ( $this->exists() ) {

					$idCondition = $this->getOriginalId();

					if ( !is_array( $idCondition ) ) {

						$idCondition = array( $primary => $idCondition );

					}

					$this->getDatabase()
						->table( $this->getTable() )
						->where( $idCondition )
						->update( $this->getModified() );


					$this->setClean();

				} else {

					$this->getDatabase()
						->table( $this->getTable() )
						->insert( $this->getData() );

					if ( !is_array( $primary ) && !isset( $this[ $primary ] ) ) {

						$this[ $primary ] = $this->getDatabase()->lastInsertId();

					}

					$this->setClean();

				}

			}

			return $this;

		}

		// make list of all rows in this tree

		$list = array();
		$this->listRows( $list );
		$count = count( $list );

		// keep iterating and saving until all references are known

		while ( true ) {

			$solvable = false;
			$clean = 0;

			foreach ( $list as $row ) {

				$row->updateReferences();

				$missing = $row->getMissing();

				if ( empty( $missing ) ) {

					$row->save( false );
					$row->updateBackReferences();
					$solvable = true;

				}

				if ( $row->isClean() ) ++$clean;

			}

			if ( !$solvable ) {

				throw new \LogicException(
					"Cannot recursively save structure (" . $this->getTable() . ") - add required values or allow NULL"
				);

			}

			if ( $clean === $count ) break;

		}

		return $this;

	}

	protected function listRows( &$list ) {

		$list[] = $this;

		foreach ( $this->_properties as $column => $value ) {

			if ( $value instanceof Row ) {

				$value->listRows( $list );

			} else if ( is_array( $value ) ) {

				foreach ( $value as $row ) {

					$row->listRows( $list );

				}

			}

		}

	}

	/**
	 * Check references and set respective keys
	 * Returns list of keys to unknown references
	 */
	function updateReferences() {

		$unknown = array();

		foreach ( $this->_properties as $column => $value ) {

			if ( $value instanceof Row ) {

				$key = $this->getDatabase()->getReference( $this->getTable(), $column );
				$this[ $key ] = $value->getId();

			}

		}

		return $unknown;

	}

	/**
	 * Check back references and set respective keys
	 */
	function updateBackReferences() {

		$id = $this->getId();

		if ( is_array( $id ) ) return;

		foreach ( $this->_properties as $column => $value ) {

			if ( is_array( $value ) ) {

				$key = $this->getDatabase()->getBackReference( $this->getTable(), $column );

				foreach ( $value as $row ) {

					$row->{ $key } = $id;

				}

			}

		}

		return $this;

	}

	/**
	 * Get missing columns, i.e. any that is null but required by the
	 * schema
	 */
	function getMissing() {

		$missing = array();
		$required = $this->getDatabase()->getRequired( $this->getTable() );

		foreach ( $required as $column => $true ) {

			if ( !isset( $this[ $column ] ) ) {

				$missing[] = $column;

			}

		}

		return $missing;

	}

	/**
	 * Update this row directly
	 */
	function update( $data, $recursive = true ) {

		return $this->setData( $data )->save( $recursive );

	}

	/**
	 * Delete this row
	 */
	function delete() {

		$result = $this->getDatabase()->table( $this->getTable() );

		$idCondition = $this->originalId;

		if ( !is_array( $idCondition ) ) {

			$primary = $this->getDatabase()->getPrimary( $this->getTable() );
			$idCondition = array( $primary => $idCondition );

		}

		$result->where( $idCondition )->delete();

		return $this->setDirty();

	}

	/**
	 * Does this row exist?
	 */
	function exists() {

		return $this->_clean || $this->_originalId !== null;

	}

	/**
	 * Is this row clean, i.e. in sync with the database?
	 */
	function isClean() {

		return empty( $this->_modified );

	}

	/**
	 * Set this row to "clean" state, i.e. in sync with database
	 */
	function setClean() {

		if ( $this->_table ) {

			$this->_originalId = $this->getId();

		}

		$this->_modified = array();

		return $this;

	}

	/**
	 * Set this row to "dirty" state, i.e. out of sync with database
	 */
	function setDirty() {

		$this->_modified = array_keys( $this->_properties );

		return $this;

	}

	/**
	 * Get root result
	 */
	function getRoot() {

		$result = $this->getResult();

		if ( $result ) return $result->getRoot();

		return $this;

	}

	/**
	 * Get value from cache
	 */
	function getCache( $key ) {

		return isset( $this->_cache[ $key ] ) ? $this->_cache[ $key ] : null;

	}

	/**
	 * Set cache value
	 */
	function setCache( $key, $value ) {

		$this->_cache[ $key ] = $value;

	}

	/**
	 * Get column, used by result if row is parent
	 */
	function getLocalKeys( $key ) {

		if ( isset( $this[ $key ] ) ) {

			return array( $this[ $key ] );

		}

		return array();

	}

	/**
	 * Get
	 */
	function getGlobalKeys( $key ) {

		$result = $this->getResult();

		if ( $result ) return $result->getGlobalKeys( $key );

		return $this->getLocalKeys( $key );

	}

	/**
	 * Get the database
	 */
	function getDatabase() {

		return $this->_db;

	}

	/**
	 * Get the bound result, if any
	 */
	function getResult() {

		return $this->_result;

	}

	/**
	 * Get the table
	 */
	function getTable() {

		return $this->_table;

	}

	// ArrayAccess

	function offsetExists( $offset ) {

		return $this->__isset( $offset );

	}

	function &offsetGet( $offset ) {

		return $this->__get( $offset );

	}

	function offsetSet( $offset, $value ) {

		$this->__set( $offset, $value );

	}

	function offsetUnset( $offset ) {

		$this->__unset( $offset );

	}

	// IteratorAggregate

	function getIterator() {

		return new \ArrayIterator( $this->_properties );

	}

	// JsonSerializable

	function jsonSerialize() {

		$array = array();

		foreach ( $this->_properties as $key => $value ) {

			if ( $value instanceof \JsonSerializable ) {

				$array[ $key ] = $value->jsonSerialize();

			} else if ( $value instanceof \DateTime ) {

				$array[ $key ] = $value->format( 'Y-m-d H:i:s' );

			} else {

				$array[ $key ] = $value;

			}

		}

		return $array;

	}

	protected $_db;

	protected $_table;

	protected $_result;

	protected $_properties;

	protected $_modified;

	protected $_clean;

	protected $_originalId;

	//

	protected $_cache = array();

}