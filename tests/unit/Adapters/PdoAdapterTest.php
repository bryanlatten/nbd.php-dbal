<?php

namespace Behance\NBD\Dbal\Adapters;

use Behance\NBD\Dbal\ConnectionService;
use Behance\NBD\Dbal\DbalException;
use Behance\NBD\Dbal\Events\QueryEvent;
use Behance\NBD\Dbal\Exceptions;
use Behance\NBD\Dbal\Sql;
use Behance\NBD\Dbal\Test\BaseTest;

use Pseudo\Pdo as PPDO;

use Symfony\Component\EventDispatcher\EventDispatcher;

class PdoAdapterTest extends BaseTest {

  private $_table        = 'my_table';
  private $_insert_data  = [ 'abc' => 123, 'def' => 456 ];
  private $_update_data  = [ 'ghi' => 789, 'created_on' => 0 ];
  private $_mock_results = [
      [
          'id'      => 1234,
          'enabled' => 1
      ]
  ];

  /**
   * @test
   * @dataProvider boolProvider
   */
  public function queryRaw( $master ) {

    $sql        = "SELECT * FROM abc WHERE def = ? && ghi = ?";
    $params     = [ 123, 456 ];
    $connect_fx = ( $master )
                  ? 'getMaster'
                  : 'getReplica';

    $connection = $this->_getDisabledMock( ConnectionService::class, [ $connect_fx ] );
    $adapter    = new PdoAdapter( $connection );
    $results    = $this->_mock_results;

    $db = new PPDO();
    $db->mock( $sql, $results );

    $connection->expects( $this->once() )
      ->method( $connect_fx )
      ->will( $this->returnValue( $db ) );

    $statement = ( $master )
                 ? $adapter->queryMaster( $sql, $params )
                 : $adapter->query( $sql, $params );

    // NOTE: fetchAll is being run against raw PDOStatement object, not Adapter wrapper fetchAll method
    $this->assertSame( $results, $statement->fetchAll( \PDO::FETCH_ASSOC ) );

  } // queryRaw


  /**
   * @test
   * @dataProvider boolProvider
   * @expectedException \Behance\NBD\Dbal\Exceptions\QueryException
   */
  public function queryBadPrepare( $master ) {

    $sql        = "INVALID--[SELECT * FROM abc WHERE def = ? && ghi = ?]--INVALID";
    $params     = [ 123, 456 ];
    $connect_fx = ( $master )
                  ? 'getMaster'
                  : 'getReplica';

    $connection = $this->_getDisabledMock( ConnectionService::class, [ $connect_fx ] );
    $pdo        = $this->_getDisabledMock( \PDO::class, [ 'prepare' ] );

    $exception  = new \PDOException( "Statement could not be prepared" );
    $adapter    = new PdoAdapter( $connection );

    $connection->expects( $this->once() )
      ->method( $connect_fx )
      ->will( $this->returnValue( $pdo ) );

    $pdo->expects( $this->once() )
      ->method( 'prepare' )
      ->will( $this->throwException( $exception ) );

    $adapter->bindEvent( PdoAdapter::EVENT_QUERY_POST_EXECUTE, function( QueryEvent $event ) use ( $sql, $exception, $params, $master ) {

      $this->assertSame( $master, $event->isUsingMaster() );
      $this->assertFalse( $event->hasStatement() );
      $this->assertTrue( $event->hasParameters() );
      $this->assertSame( $params, $event->getParameters() );
      $this->assertSame( $sql, $event->getQuery() );
      $this->assertTrue( $event->hasException() );
      $this->assertInstanceOf( Exceptions\QueryException::class, $event->getException() );
      $this->assertNotSame( $exception, $event->getException() );

    } ); // bindEvent

    if ( $master ) {
      $adapter->queryMaster( $sql, $params );
    }
    else {
      $adapter->query( $sql, $params );
    }

  } // queryBadPrepare


  /**
   * @test
   * @dataProvider boolProvider
   */
  public function queryReconnect( $master ) {

    $sql        = "SELECT * FROM abc WHERE def = ? && ghi = ?";
    $params     = [ 123, 456 ];
    $connect_fx = ( $master )
                  ? '_getMasterAdapter'
                  : '_getReplicaAdapter';

    $connection = $this->_getDisabledMock( ConnectionService::class, [ 'reconnect' ] );

    $connection->expects( $this->once() )
      ->method( 'reconnect' );

    $methods    = [ '_getMasterAdapter', '_getReplicaAdapter' ];
    $adapter    = $this->getMockBuilder( PdoAdapter::class )
                      ->setMethods( $methods )
                      ->setConstructorArgs( [ $connection ] )
                      ->getMock();

    $pdo        = $this->_getDisabledMock( \PDO::class, [ 'prepare' ] );
    $statement  = $this->_getDisabledMock( \PDOStatement::class, [ 'execute' ] );

    $pdo->expects( $this->exactly( 2 ) )
      ->method( 'prepare' )
      ->will( $this->returnValue( $statement ) );

    $adapter->expects( $this->exactly( 2 ) )
      ->method( $connect_fx )
      ->will( $this->returnValue( $pdo ) );

    $exception = new \PDOException( "Mysql " . PdoAdapter::MESSAGE_SERVER_GONE_AWAY );

    $statement->expects( $this->exactly( 2 ) )
      ->method( 'execute' )
      ->will( $this->onConsecutiveCalls( $this->throwException( $exception ), true ) );

    $event_count = 0;
    $callback    = ( function( QueryEvent $event ) use ( &$event_count, $statement, $exception ) {

      $this->assertTrue( $event->hasStatement() );
      $this->assertSame( $statement, $event->getStatement() );

      if ( $event_count === 0 ) {

        $this->assertTrue( $event->hasException() );
        $this->assertInstanceOf( DbalException::class, $event->getException() );

      } // if event_count = 0

      else {

        $this->assertFalse( $event->hasException() );
        $this->assertNull( $event->getException() );

      } // else (event_count != 0)

      ++$event_count;

    } );

    $adapter->bindEvent( PdoAdapter::EVENT_QUERY_POST_EXECUTE, $callback );

    $result = ( $master )
              ? $adapter->queryMaster( $sql, $params )
              : $adapter->query( $sql, $params );

    $this->assertSame( $statement, $result );

    $this->assertEquals( 2, $event_count );

  } // queryReconnect


  /**
   * @test
   * @dataProvider badReconnectProvider
   * @expectedException Behance\NBD\Dbal\Exceptions\QueryException
   */
  public function queryReconnectBad( $master, $in_transaction ) {

    $sql        = "SELECT * FROM abc WHERE def = ? && ghi = ?";
    $params     = [ 123, 456 ];
    $connect_fx = ( $master )
                  ? '_getMasterAdapter'
                  : '_getReplicaAdapter';
    $methods    = [ 'isInTransaction', '_getMasterAdapter', '_getReplicaAdapter', '_reconnectAdapter' ];
    $connection = $this->_getDisabledMock( ConnectionService::class );
    $adapter    = $this->getMockBuilder( PdoAdapter::class )
                       ->setMethods( $methods )
                       ->setConstructorArgs( [ $connection ] )
                       ->getMock();

    $adapter->method( 'isInTransaction' )
      ->willReturn( $in_transaction );

    $pdo        = $this->_getDisabledMock( \PDO::class, [ 'prepare' ] );
    $statement  = $this->_getDisabledMock( \PDOStatement::class, [ 'execute' ] );

    $expected   = ( $in_transaction )
                  ? 1
                  : 2;

    $pdo->expects( $this->exactly( $expected ) )
      ->method( 'prepare' )
      ->will( $this->returnValue( $statement ) );

    $adapter->expects( $this->exactly( $expected ) )
      ->method( $connect_fx )
      ->will( $this->returnValue( $pdo ) );

    if ( $in_transaction ) {
      $adapter->expects( $this->never() )
        ->method( '_reconnectAdapter' );
    }
    else {
      $adapter->expects( $this->once() )
        ->method( '_reconnectAdapter' )
        ->will( $this->returnValue( $pdo ) );
    }

    $statement->expects( $this->atLeastOnce() )
      ->method( 'execute' )
      ->will( $this->throwException( new \PDOException( "Mysql " . PdoAdapter::MESSAGE_SERVER_GONE_AWAY ) ) );

    if ( $master ) {
      $adapter->queryMaster( $sql, $params );
    }
    else {
      $adapter->query( $sql, $params );
    }

  } // queryReconnectBad


  /**
   * @return array
   */
  public function badReconnectProvider() {

    return [
        [ false, false ],
        [ true, false ],
        [ false, true ],
        [ true, true ],
    ];

  } // badReconnectProvider


  /**
   * @test
   * @dataProvider insertDataProvider
   */
  public function insert( $insert_data ) {

    $insert_id = '12345';

    $adapter             = $this->_getDisabledMock( PdoAdapter::class, [ '_getMasterAdapter', '_executeMaster' ] );
    $pdo                 = $this->_getDisabledMock( \PDO::class, [ 'lastInsertId' ] );
    $statement           = $this->_getDisabledMock( \PDOStatement::class );
    $expected_column_sql = '(`' . implode( '`, `', array_keys( $insert_data ) ) . '`)';

    $adapter->expects( $this->once() )
      ->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $adapter->expects( $this->once() )
      ->method( '_executeMaster' )
      ->with( $this->stringContains( "INSERT INTO `{$this->_table}` {$expected_column_sql}" ), $this->isType( 'array' ) )
      ->will( $this->returnValue( $statement ) );

    $pdo->expects( $this->once() )
      ->method( 'lastInsertId' )
      ->will( $this->returnValue( $insert_id ) );

    $this->assertEquals( $insert_id, $adapter->insert( $this->_table, $insert_data ) );

  } // insert


  /**
   * @test
   */
  public function insertNonIntegerKey() {

    $insert_data         = [ 'key' => 'value', 'type' => 'value' ];
    $adapter             = $this->_getDisabledMock( PdoAdapter::class, [ '_getMasterAdapter', '_executeMaster' ] );
    $pdo                 = $this->_getDisabledMock( \PDO::class, [ 'lastInsertId' ] );
    $statement           = $this->_getDisabledMock( \PDOStatement::class, [ 'rowCount' ] );
    $expected_column_sql = '(`' . implode( '`, `', array_keys( $insert_data ) ) . '`)';

    $adapter->expects( $this->once() )
      ->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $adapter->expects( $this->once() )
      ->method( '_executeMaster' )
      ->with( $this->stringContains( "INSERT INTO `{$this->_table}` {$expected_column_sql}" ), $this->isType( 'array' ) )
      ->will( $this->returnValue( $statement ) );

    $pdo->expects( $this->once() )
      ->method( 'lastInsertId' )
      ->will( $this->returnValue( '0' ) );

    $statement->expects( $this->once() )
      ->method( 'rowCount' )
      ->will( $this->returnValue( 1 ) );

    $this->assertEquals( 1, $adapter->insert( $this->_table, $insert_data ) );

  } // insertNonIntegerKey


  /**
   * @return array
   */
  public function insertDataProvider() {

    $extra_data = $this->_insert_data;

    $extra_data['created_on']  = new Sql( 'NOW()' );
    $extra_data['modified_on'] = new Sql( 'NOW()' );

    return [
        'Without SQL'          => [ $this->_insert_data ],
        'With'                 => [ $extra_data ],
        'Keyword Column Names' => [ [ 'key' => 'value', 'type' => 'value' ] ],
    ];

  } // insertDataProvider


  /**
   * @return array
   */
  public function updateDataProvider() {

    $extra_data = $this->_update_data;

    $extra_data['modified_on'] = new Sql( 'NOW()' );

    return [
        'Without SQL' => [ $this->_update_data ],
        'With'        => [ $extra_data ]
    ];

  } // updateDataProvider

  /**
   * @test
   * @expectedException Behance\NBD\Dbal\Exceptions\InvalidQueryException
   */
  public function insertNonAssociative() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class );

    $adapter->insert( $this->_table, [ 'apples', 'oranges', 'peaches' ] );

  } // insertNonAssociative


  /**
   * Proves the ->insert() interface remains intact
   *
   * @test
   */
  public function insertIgnore() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class, [ 'insert' ] );
    $result  = '12345';

    $adapter->expects( $this->once() )
      ->method( 'insert' )
      ->with( $this->_table, $this->_insert_data, [ 'ignore' => true ] )
      ->will( $this->returnValue( $result ) );

    $this->assertSame( $result, $adapter->insertIgnore( $this->_table, $this->_insert_data ) );

  } // insertIgnore


  /**
   * Ensures that unmocked version of InsertIgnore object is processed correctly
   *
   * @test
   * @dataProvider boolProvider
   */
  public function insertIgnoreRaw( $ignored ) {

    $insert_id = '12345';

    $adapter   = $this->_getDisabledMock( PdoAdapter::class, [ '_getMasterAdapter', '_executeMaster' ] );
    $pdo       = $this->_getDisabledMock( \PDO::class, [ 'lastInsertId' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class );
    $insert_id = ( $ignored )
                 ? false
                 : $insert_id;

    $adapter->expects( $this->once() )
      ->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $adapter->expects( $this->once() )
      ->method( '_executeMaster' )
      ->with( $this->stringContains( "INSERT IGNORE INTO `{$this->_table}`" ), $this->isType( 'array' ) )
      ->will( $this->returnValue( $statement ) );

    $pdo->expects( $this->once() )
      ->method( 'lastInsertId' )
      ->will( $this->returnValue( $insert_id ) );

    $expected = ( $ignored )
                ? 0
                : $insert_id;

    $this->assertEquals( $expected, $adapter->insertIgnore( $this->_table, $this->_insert_data ) );

  } // insertIgnoreRaw


  /**
   * @test
   * @expectedException Behance\NBD\Dbal\Exceptions\QueryRequirementException
   */
  public function insertOnDuplicateNoUpdate() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class );

    $adapter->insertOnDuplicateUpdate( $this->_table, $this->_insert_data, [] );

  } // insertOnDuplicateNoUpdate

  /**
   * @test
   * @expectedException Behance\NBD\Dbal\Exceptions\InvalidQueryException
   */
  public function insertOnDuplicateIncorrectData() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class );

    $adapter->insertOnDuplicateUpdate( $this->_table, $this->_insert_data, [ 'foo' ] );

  } // insertOnDuplicateIncorrectData

  /**
   * @test
   * @expectedException Behance\NBD\Dbal\Exceptions\InvalidQueryException
   */
  public function insertOnDuplicateIncorrectObject() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class );

    $adapter->insertOnDuplicateUpdate( $this->_table, $this->_insert_data, [ 'foo' => new \stdClass ] );

  } // insertOnDuplicateIncorrectObject


  /**
   * @test
   */
  public function insertOnDuplicateUpdate() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class, [ 'insert' ] );
    $result  = '12345';

    $adapter->expects( $this->once() )
      ->method( 'insert' )
      ->with( $this->_table, $this->_insert_data, [ 'on_duplicate' => $this->_update_data ] )
      ->will( $this->returnValue( $result ) );

    $this->assertSame( $result, $adapter->insertOnDuplicateUpdate( $this->_table, $this->_insert_data, $this->_update_data ) );

  } // insertOnDuplicateUpdate


  /**
   * @test
   * @dataProvider boolProvider
   */
  public function insertOnDuplicateUpdateRaw( $inserted ) {

    $adapter   = $this->_getDisabledMock( PdoAdapter::class, [ '_execute', '_getMasterAdapter' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class );
    $pdo       = $this->_getDisabledMock( \PDO::class, [ 'lastInsertId' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class, [ 'rowCount' ] );
    $result    = ( $inserted )
                 ? '12345' // Last insert ID
                 : 2;    // Returned by mysql on update

    if ( !$inserted ) {

      $statement->expects( $this->once() )
        ->method( 'rowCount' )
        ->will( $this->returnValue( $result ) );

    } // if inserted

    $adapter->expects( $this->once() )
      ->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $adapter->expects( $this->once() )
      ->method( '_execute' )
      ->with( $this->stringContains( "INSERT INTO" ), $this->isType( 'array' ), true )
      ->will( $this->returnValue( $statement ) );

    $last_id = ( $inserted )
               ? $result
               : '0';

    $pdo->expects( $this->once() )
      ->method( 'lastInsertId' )
      ->will( $this->returnValue( $last_id ) );

    $this->assertSame( $result, $adapter->insertOnDuplicateUpdate( $this->_table, $this->_insert_data, $this->_update_data ) );

  } // insertOnDuplicateUpdateRaw


  /**
   * @test
   */
  public function insertOnDuplicateUpdateSameData() {

    $adapter   = $this->_getDisabledMock( PdoAdapter::class, [ '_execute', '_getMasterAdapter' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class );
    $pdo       = $this->_getDisabledMock( \PDO::class, [ 'lastInsertId' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class, [ 'rowCount' ] );

    $statement->expects( $this->once() )
      ->method( 'rowCount' )
      ->will( $this->returnValue( 0 ) );

    $adapter->expects( $this->once() )
      ->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $adapter->expects( $this->once() )
      ->method( '_execute' )
      ->with( $this->stringContains( "INSERT INTO" ), $this->isType( 'array' ), true )
      ->will( $this->returnValue( $statement ) );

    $pdo->expects( $this->once() )
      ->method( 'lastInsertId' )
      ->will( $this->returnValue( '0' ) );

    $this->assertSame( 0, $adapter->insertOnDuplicateUpdate( $this->_table, $this->_insert_data, $this->_update_data ) );

  } // insertOnDuplicateUpdateSameData

  /**
   * @test
   * @dataProvider boolProvider
   */
  public function insertOnDuplicateUpdateRawNonIntegerKey( $inserted ) {

    $adapter   = $this->_getDisabledMock( PdoAdapter::class, [ '_execute', '_getMasterAdapter' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class );
    $pdo       = $this->_getDisabledMock( \PDO::class, [ 'lastInsertId' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class, [ 'rowCount' ] );
    $result    = ( $inserted )
                 ? 1  // Row inserted
                 : 2; // Row updated

    $statement->expects( $this->once() )
      ->method( 'rowCount' )
      ->will( $this->returnValue( $result ) );

    $adapter->expects( $this->once() )
      ->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $adapter->expects( $this->once() )
      ->method( '_execute' )
      ->with( $this->stringContains( "INSERT INTO" ), $this->isType( 'array' ), true )
      ->will( $this->returnValue( $statement ) );

    $last_id = '0';

    $pdo->expects( $this->once() )
      ->method( 'lastInsertId' )
      ->will( $this->returnValue( $last_id ) );

    $this->assertSame( $result, $adapter->insertOnDuplicateUpdate( $this->_table, $this->_insert_data, $this->_update_data ) );

  } // insertOnDuplicateUpdateRawNonIntegerKey


  /**
   * @test
   */
  public function quote() {

    $value      = 'won\'t matter';
    $result     = "won\\'t matter";

    $connection = $this->_getDisabledMock( ConnectionService::class, [ 'getMaster' ] );
    $pdo        = $this->_getDisabledMock( \PDO::class, [ 'quote' ] );
    $adapter    = $this->getMockBuilder( PdoAdapter::class )
                       ->setMethods( [ '_getReplicaAdapter' ] )
                       ->setConstructorArgs( [ $connection ] )
                       ->getMock();

    $pdo->expects( $this->once() )
      ->method( 'quote' )
      ->with( $value )
      ->will( $this->returnValue( $result ) );
    $adapter->expects( $this->once() )
      ->method( '_getReplicaAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $this->assertSame( $result, $adapter->quote( $value ) );

  } // quote


  /**
   * @test
   * @expectedException Behance\NBD\Dbal\Exceptions\InvalidQueryException
   */
  public function updateNoData() {

    $connection = $this->_getDisabledMock( ConnectionService::class );
    $adapter    = new PdoAdapter( $connection );
    $adapter->update( 'abc', [], 'abc=1' );

  } // updateNoData


  /**
   * @test
   * @expectedException Behance\NBD\Dbal\Exceptions\QueryRequirementException
   */
  public function updateNoWhere() {

    $connection = $this->_getDisabledMock( ConnectionService::class );
    $adapter    = new PdoAdapter( $connection );
    $adapter->update( 'abc', [ 'xyz' => 123 ], '' );

  } // updateNoWhere


  /**
   * @test
   * @dataProvider badWhereProvider
   * @expectedException Behance\NBD\Dbal\Exceptions\InvalidQueryException
   */
  public function updateBadWhere( $where ) {

    $connection = $this->_getDisabledMock( ConnectionService::class );
    $adapter    = new PdoAdapter( $connection );
    $adapter->update( 'abc', [ 'xyz' => 123 ], $where );

  } // updateBadWhere


  /**
   * @return array
   */
  public function badWhereProvider() {

    return [
        [ 456 ],
        [ new \stdClass() ],
    ];

  } // badWhereProvider

  /**
   * @test
   * @dataProvider whereProvider
   */
  public function updateWhere( $where ) {

    $affected  = 1;
    $adapter   = $this->_getDisabledMock( PdoAdapter::class, [ '_executeMaster' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class, [ 'rowCount' ] );

    $adapter->expects( $this->once() )
      ->method( '_executeMaster' )
      ->with( $this->stringContains( "UPDATE `{$this->_table}` SET " ) )
      ->will( $this->returnValue( $statement ) );

    $statement->expects( $this->once() )
      ->method( 'rowCount' )
      ->will( $this->returnValue( $affected ) );

    $this->assertSame( $affected, $adapter->update( $this->_table, $this->_update_data, $where ) );

  } // updateWhere


  /**
   * @test
   * @dataProvider updateDataProvider
   */
  public function update( $update_data ) {

    $affected  = 1;
    $adapter   = $this->_getDisabledMock( PdoAdapter::class, [ '_executeMaster' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class, [ 'rowCount' ] );

    $adapter->expects( $this->once() )
      ->method( '_executeMaster' )
      ->with( $this->stringContains( "UPDATE `{$this->_table}` SET" ) )
      ->will( $this->returnValue( $statement ) );

    $statement->expects( $this->once() )
      ->method( 'rowCount' )
      ->will( $this->returnValue( $affected ) );

    $this->assertSame( $affected, $adapter->update( $this->_table, $update_data, [ 'id' => 5 ] ) );

  } // update


  /**
   * @test
   * @dataProvider whereProvider
   */
  public function delete( $where ) {

    $affected  = 1;
    $adapter   = $this->_getDisabledMock( PdoAdapter::class, [ '_executeMaster' ] );
    $statement = $this->_getDisabledMock( \PDOStatement::class, [ 'rowCount' ] );

    $adapter->expects( $this->once() )
      ->method( '_executeMaster' )
      ->with( $this->stringContains( "DELETE FROM `{$this->_table}` WHERE " ) )
      ->will( $this->returnValue( $statement ) );

    $statement->expects( $this->once() )
      ->method( 'rowCount' )
      ->will( $this->returnValue( $affected ) );

    $this->assertSame( $affected, $adapter->delete( $this->_table, $where ) );

  } // delete


  /**
   * @return array
   */
  public function whereProvider() {

    return [
        "String"          => [ 'id=1' ],
        "Array Strings"   => [ [ 'id=1', 'second_id=2' ] ],
        "Array Key:Value" => [ [ 'id' => 1 ] ],
        "Array Key:Value" => [ [ 'id' => 1, 'second_id' => 2 ] ]

    ];

  } // whereProvider


  /**
   * @test
   * @expectedException Behance\NBD\Dbal\Exceptions\QueryRequirementException
   */
  public function deleteNoWhere() {

    $connection = $this->_getDisabledMock( ConnectionService::class );
    $adapter    = new PdoAdapter( $connection );
    $adapter->delete( 'abc', '' );

  } // deleteNoWhere


  /**
   * @test
   * @dataProvider badWhereProvider
   * @expectedException Behance\NBD\Dbal\Exceptions\InvalidQueryException
   */
  public function deleteBadWhere( $where ) {

    $connection = $this->_getDisabledMock( ConnectionService::class );
    $adapter    = new PdoAdapter( $connection );
    $adapter->delete( 'abc', $where );

  } // deleteBadWhere


  /**
   * @test
   */
  public function beginTransaction() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class, [ '_getMasterAdapter' ] );
    $pdo     = $this->_getDisabledMock( PDO::class, [ 'beginTransaction' ] );

    $adapter->expects( $this->once() )
      ->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $pdo->expects( $this->once() )
      ->method( 'beginTransaction' )
      ->will( $this->returnValue( true ) );

    $this->assertTrue( $adapter->beginTransaction() );
    $this->assertTrue( $adapter->isInTransaction() );

  } // beginTransaction


  /**
   * @test
   */
  public function commit() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class, [ '_getMasterAdapter' ] );
    $pdo     = $this->_getDisabledMock( PDO::class, [ 'commit', 'beginTransaction' ] );

    $adapter->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $pdo->expects( $this->once() )
      ->method( 'commit' )
      ->will( $this->returnValue( true ) );

    $adapter->beginTransaction();

    $this->assertTrue( $adapter->isInTransaction() );
    $this->assertTrue( $adapter->commit() );
    $this->assertFalse( $adapter->isInTransaction() );

  } // commit


  /**
   * @test
   */
  public function rollBack() {

    $adapter = $this->_getDisabledMock( PdoAdapter::class, [ '_getMasterAdapter' ] );
    $pdo     = $this->_getDisabledMock( PDO::class, [ 'rollBack', 'beginTransaction' ] );

    $adapter->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $pdo->expects( $this->once() )
      ->method( 'rollBack' )
      ->will( $this->returnValue( true ) );

    $adapter->beginTransaction();

    $this->assertTrue( $adapter->isInTransaction() );
    $this->assertTrue( $adapter->rollBack() );
    $this->assertFalse( $adapter->isInTransaction() );

  } // rollBack


  /**
   * @test
   */
  public function closeConnection() {

    $connection = $this->_getDisabledMock( ConnectionService::class, [ 'closeOpenedConnections' ] );
    $pdo        = $this->_getDisabledMock( PDO::class, [ 'beginTransaction' ] );
    $adapter    = $this->getMockBuilder( PdoAdapter::class )
                      ->setMethods( [ '_getMasterAdapter' ] )
                      ->setConstructorArgs( [ $connection ] )
                      ->getMock();

    $connection->expects( $this->once() )
      ->method( 'closeOpenedConnections' );

    $adapter->method( '_getMasterAdapter' )
      ->will( $this->returnValue( $pdo ) );

    $adapter->beginTransaction();

    $this->assertTrue( $adapter->isInTransaction() );

    $adapter->closeConnection();

    $this->assertFalse( $adapter->isInTransaction() );

  } // closeConnection


  /**
   * @return array
   */
  public function boolProvider() {

    return [
        [ true ],
        [ false ]
    ];

  } // boolProvider

} // PdoAdapterTest
