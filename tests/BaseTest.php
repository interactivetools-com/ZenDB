<?php
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection UnnecessaryContinueInspection */
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */
declare(strict_types=1);

namespace tests;

// Setup autoload

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Itools\ZenDB\DB;

DB::config();

abstract class BaseTest extends TestCase
{
    // Initialize configDefaults with values from _ENV (set in phpunit.xml)
    protected static array $configDefaults = [
        'hostname'               => null, // Will be set in setUpBeforeClass
        'username'               => null,
        'password'               => null,
        'database'               => null,
        'tablePrefix'            => 'test_',     // prefix for all table names, e.g., 'cms_'
        'primaryKey'             => 'num',       // primary key used for shortcut where = (int) num queries

        'usePhpTimezone'         => true,        // Set MySQL timezone to the same offset as current PHP timezone
        'set_sql_mode'           => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY',
        'versionRequired'        => '5.7.32',    // minimum MySQL version required. An exception will be thrown if the server version is lower than this.
        'requireSSL'             => false,       // require SSL connections
        'databaseAutoCreate'     => true,        // automatically creates database if it doesn't exist
        'connectTimeout'         => 1,           // (low timeout for testing) connection timeout in seconds, sets MYSQLI_OPT_CONNECT_TIMEOUT
        'readTimeout'            => 60,          // read timeout in seconds, sets MYSQLI_OPT_READ_TIMEOUT
    ];

    public static function setUpBeforeClass(): void
    {
        // Set database credentials from environment variables
        // Set here because we can't set it at compile time
        self::$configDefaults['hostname'] = $_ENV['DB_HOSTNAME'];
        self::$configDefaults['username'] = $_ENV['DB_USERNAME'];
        self::$configDefaults['password'] = $_ENV['DB_PASSWORD'];
        self::$configDefaults['database'] = $_ENV['DB_DATABASE'];
    }

    public static function resetTempTestTables(): void {

        // create temporary tables with test data
        $sql = <<<__SQL__
DROP TEMPORARY TABLE IF EXISTS test_users;
CREATE TEMPORARY TABLE test_users (num INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255), 
  isAdmin TINYINT(1) NULL,
  status ENUM('Active', 'Inactive', 'Suspended'),
  city VARCHAR(255),
  dob DATE,
  age INT
);

INSERT INTO test_users (num, name, isAdmin, status, city, dob, age) VALUES
    (1, 'John Doe', 1, 'Active', 'Vancouver', '1985-04-10', 38),
    (2, 'Jane Janey Doe', NULL, 'Inactive', 'Toronto', '1990-06-15', 33), 
    (3, 'Alice Smith', 1, 'Active', 'Montreal', '1980-12-20', 43),
    (4, 'Bob Johnson', 0, 'Suspended', 'Calgary', '1995-02-25', 28),
    (5, 'Charlie Brown', 1, 'Active', 'Edmonton', '1989-11-11', 34),
    (6, 'Dave Williams', 0, 'Inactive', 'Ottawa', '1975-09-30', 48),
    (7, 'Erin Davis', NULL, 'Active', 'Quebec', '1998-03-14', 25),
    (8, 'Frank <b>Miller</b>', 0, 'Suspended', 'Winnipeg', '1992-07-22', 31),
    (9, 'George Wilson', 1, 'Active', 'Halifax', '1970-10-05', 53),
    (10, 'Helen Clark', 0, 'Inactive', 'Saskatoon', '1986-05-16', 37),
    (11, 'Ivan Scott', 1, 'Active', 'Victoria', '2000-01-01', 24), 
    (12, 'Jill Taylor', 0, 'Suspended', 'Hamilton', '1999-04-08', 25),
    (13, 'Kevin Lewis', 1, 'Active', 'Kitchener', '1988-08-19', 35),
    (14, 'Linda Harris', NULL, 'Inactive', 'London', '1978-11-21', 45),
    (15, 'Mike Nelson', 1, 'Active', 'Windsor', '1994-02-28', 30),
    (16, 'Nancy Allen', 0, 'Suspended', 'Toronto', '1985-12-24', 38),
    (17, 'Oliver Young', 1, 'Active', 'Fredericton', '1997-06-30', 26),
    (18, 'Paula Hall', 0, 'Inactive', 'St. John\'s', '1982-10-15', 41), 
    (19, 'Quentin Adams', NULL, 'Active', 'Charlottetown', '1991-03-31', 32),
    (20, 'Rachel Carter', 0, 'Suspended', 'Yellowknife', '1979-07-04', 44);

-- Create temporary table for test_orders
DROP TEMPORARY TABLE IF EXISTS test_orders;
CREATE TEMPORARY TABLE test_orders (
    order_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    order_date DATE,
    total_amount DECIMAL(10, 2)
);

-- Insert additional sample data into test_orders
INSERT INTO test_orders (user_id, order_date, total_amount) VALUES
    (6, '2023-06-25', 80.00),
    (7, '2023-07-10', 120.50),
    (8, '2023-08-15', 45.75),
    (9, '2023-09-20', 175.25),
    (10, '2023-10-05', 60.00),
    (11, '2023-11-18', 90.00),
    (12, '2023-12-03', 110.00),
    (13, '2024-01-22', 70.50),
    (14, '2024-02-12', 30.25),
    (15, '2024-03-08', 95.75);

-- Create temporary table for products
DROP TEMPORARY TABLE IF EXISTS test_products;
CREATE TEMPORARY TABLE test_products (
    product_id INT PRIMARY KEY AUTO_INCREMENT,
    product_name VARCHAR(255),
    price DECIMAL(8, 2)
);

-- Insert sample data into test_products
INSERT INTO test_products (product_name, price) VALUES
    ('Product A', 10.99),
    ('Product B', 5.50),
    ('Product C', 25.75),
    ('Product D', 8.25),
    ('Product E', 15.99);

-- Create temporary table for order details
DROP TEMPORARY TABLE IF EXISTS test_order_details;
CREATE TEMPORARY TABLE test_order_details (
    order_detail_id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT,
    product_id INT,
    quantity INT
);

-- Insert sample data into test_order_details with 30 records
INSERT INTO test_order_details (order_id, product_id, quantity) VALUES
    (1, 1, 2),
    (2, 3, 1),
    (3, 2, 4),
    (4, 5, 3),
    (5, 4, 1),
    (6, 1, 3),
    (7, 2, 2),
    (8, 3, 1),
    (9, 4, 4),
    (10, 5, 2),
    (11, 1, 1),
    (12, 2, 3),
    (13, 3, 2),
    (14, 4, 1),
    (15, 5, 3),
    (16, 1, 2),
    (17, 2, 1),
    (18, 3, 4),
    (19, 4, 3),
    (20, 5, 2),
    (21, 1, 4),
    (22, 2, 3),
    (23, 3, 2),
    (24, 4, 1),
    (25, 5, 4),
    (26, 1, 2),
    (27, 2, 1),
    (28, 3, 4),
    (29, 4, 3),
    (30, 5, 2);

__SQL__;

        // Execute SQL
        $db = DB::$mysqli;
        if (!$db->multi_query($sql)) {
            throw new RuntimeException("Error inserting records: ".$db->error);
        }
        // Consume all results - the connection will be busy, and you'll get "out of sync" errors until you do this.
        while ($db->next_result()) {
            /** @noinspection PhpUnnecessaryStopStatementInspection */
            continue;
        }
    }

}
