<?php
/**
 * Method này sẽ tạo ra 1 hồ chứa object, mục đích là giúp cho các connection của ta không phát triền quá nhanh, khi object được tạo rồi, và ta lại gọi nó nữa thì sẽ kiểm tra xem object cũ còn hiệu lực không, nếu còn thì dùng object này, không thì sẽ tạo mới. Cách dùng là ta tạo object, checkout ra dùng, sau khi dùng xong tính năng của nó thì ta checkin lại để dành, sau này cần dùng chỉ cần checkout ra và dùng tiếp.
 */
abstract class ObjectPool {
    private $expirationTime;
    private $hashTable;
    private $locked;
    private $unlocked;

    public function __construct() {
        $this->expirationTime = 30; // 30 seconds
        $this->locked = [];
        $this->unlocked = [];
    }

    abstract function create();

    abstract function validate($conn);

    abstract function expire($conn);

    public function getLocked() {
      return $this->locked;
    }

    public function getUnLocked() {
      return $this->unlocked;
    }

    public function checkOut() {
        if (count($this->unlocked)) {
            foreach ($this->unlocked as $item) {
                if ((date('YmdHis') - $item['createAt']) > $this->expirationTime) {
                    $this->removeConnection($this->unlocked, $item['conn']);
                    $this->expire($item['conn']);
                    $item['conn'] = null;
                } else {
                    if ($this->validate($item['conn'])) {
                        $this->removeConnection($this->unlocked, $item['conn']);
                        $this->locked[] = [
                            'conn' => $item['conn'],
                            'createAt' => date('YmdHis'),
                        ];
                        return $item['conn'];
                    } else {
                        // object failed validation
                        $this->removeConnection($this->unlocked, $item['conn']);
                        $this->expire($item['conn']);
                        $item['conn'] = null;
                    }
                }
            }
        }
        $conn = $this->create();
        $this->locked[] = [
            'conn' => $conn,
            'createAt' => date('YmdHis'),
        ];
        return $conn;
    }

    public function checkIn($conn) {
        $this->removeConnection($this->locked, $conn);
        $this->unlocked[] = [
            'conn' => $conn,
            'createAt' => date('YmdHis'),
        ];
    }

    private function removeConnection(&$items, $conn) {
        if (count($items)) {
            $tmp = [];
            foreach ($items as $item) {
                if ($item['conn'] === $conn) {
                    continue;
                }
                $tmp[] = $item;
            }
            $items = $tmp;
            unset($tmp);
        }
    }
}

class MysqlConnectionPool extends ObjectPool {
    private $driver, $dsn, $usr, $pwd, $dbName;

    public function __construct($driver, $dsn, $usr, $pwd, $dbName) {
        parent::__construct();
        $this->driver = $driver;
        $this->dsn = $dsn;
        $this->usr = $usr;
        $this->pwd = $pwd;
        $this->dbName = $dbName;
    }

    public function create() {
        try {
            switch ($this->driver) {
            case 'mysqli':
                return new mysqli($this->dsn, $this->usr, $this->pwd, $this->dbName);
                break;
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return (null);
        }
    }

    public function expire($conn) {
        try {
            switch ($this->driver) {
            case 'mysqli':
                $conn->close();
                break;
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function validate($conn) {
        try {
            switch ($this->driver) {
            case 'mysqli':
                return $conn->ping();
                break;
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return false;
        }
    }

}

// Init connection
$pool = new MysqlConnectionPool('mysqli', '127.0.0.1', 'root', 'ifrc', 'employees');

$conn = $pool->checkOut(); // Get a connection:
$pool->checkIn($conn); // Return the connection after used
$conn = $pool->checkOut(); // Get a connection:
$pool->checkIn($conn); // Return the connection after used
$conn = $pool->checkOut(); // Get a connection:
$conn1 = $pool->checkOut(); // Get a connection:
$pool->checkIn($conn); // Return the connection after used
$pool->checkIn($conn1); // Return the connection after used
$conn = $pool->checkOut(); // Get a connection:
$pool->checkIn($conn); // Return the connection after used
var_dump(count($pool->getLocked()));
var_dump(count($pool->getUnLocked()));
$conn = $pool->checkOut(); // Get a connection:
$conn1 = $pool->checkOut(); // Get a connection:
var_dump(count($pool->getLocked()));
var_dump(count($pool->getUnLocked()));
$pool->checkIn($conn); // Return the connection after used
$pool->checkIn($conn1); // Return the connection after used
var_dump(count($pool->getLocked()));
var_dump(count($pool->getUnLocked()));