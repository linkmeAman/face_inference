<?php
/** Request-local connection ownership; inactive outside the six-module boundary. */
class ModuleFlowConnection extends mysqli
{
    private int $flowDepth = 0;
    public static string $centralSchema = 'ccc_central';

    public function __construct(?string $hostname=null, ?string $username=null, ?string $password=null, ?string $database=null, ?int $port=null, ?string $socket=null)
    {
        parent::__construct($hostname,$username,$password,$database,$port,$socket);
        if ($database==='ccc_central' || preg_match('/^ccc_central_test(?:_[a-z0-9_]+)?$/D',(string)$database)) self::$centralSchema=$database;
    }

    private function schemaAliases(string $query): string
    {
        $pattern = "~'(?:\\\\.|''|[^'\\\\])*'(*SKIP)(*F)|\"(?:\\\\.|\"\"|[^\"\\\\])*\"(*SKIP)(*F)|`?ccc_central`?\\s*\\.~";
        return preg_replace($pattern, '`'.self::$centralSchema.'`.', $query);
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        if (class_exists('ModuleFlowRuntime', false) && ModuleFlowRuntime::active()) {
            $query = ModuleFlowRuntime::qualify($query);
        }
        return parent::query($this->schemaAliases($query), $result_mode);
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        if (class_exists('ModuleFlowRuntime', false) && ModuleFlowRuntime::active()) {
            $query = ModuleFlowRuntime::qualify($query);
        }
        return parent::prepare($this->schemaAliases($query));
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        if ($this->flowDepth++ > 0) return parent::query('SAVEPOINT module_flow_'.$this->flowDepth);
        return parent::begin_transaction($flags, $name);
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        if ($this->flowDepth > 1) return parent::query('RELEASE SAVEPOINT module_flow_'.$this->flowDepth--);
        $this->flowDepth = 0;
        return parent::commit($flags, $name);
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        if ($this->flowDepth > 1) {
            $level = $this->flowDepth--;
            parent::query('ROLLBACK TO SAVEPOINT module_flow_'.$level);
            return parent::query('RELEASE SAVEPOINT module_flow_'.$level);
        }
        $this->flowDepth = 0;
        return parent::rollback($flags, $name);
    }

    public function close(): true
    {
        if (class_exists('ModuleFlowRuntime', false) && ModuleFlowRuntime::owns($this)) return true;
        return parent::close();
    }
}
