<?php

namespace UMA;

/**
 * @author Marcel Hernandez
 */
class RedisSessionHandler extends \SessionHandler
{
    /**
     * The maximum number of seconds that any given
     * session can remain locked. This is only meant
     * as a last resort releasing mechanism if for an
     * unknown reason the PHP engine never
     * calls RedisSessionHandler::close().
     *
     * $lock_ttl is set to the 'max_execution_time'
     * runtime configuration value.
     *
     * @var int
     */
    private $lock_ttl;

    /**
     * The maximum number of seconds that a session
     * will be kept before it is considered stale and is
     * purged from Redis.
     *
     * $session_ttl is set to the 'session.gc_maxlifetime'
     * runtime configuration value.
     *
     * @var int
     */
    private $session_ttl;

    /**
     * A collection of every session ID that is being locked by
     * the current thread of execution. When session_write_close()
     * is called the locks on all these ID will be lifted.
     *
     * @var string[]
     */
    private $open_sessions;

    /**
     * A collection of every session ID that has been generated
     * in the current thread of execution.
     *
     * @var string[]
     */
    private $new_sessions;

    /**
     * @var \Redis
     */
    private $redis;

    public function __construct()
    {
        if (false === extension_loaded('redis')) {
            throw new \RuntimeException("the 'redis' extension is needed in order to use this session handler");
        }

        $this->lock_ttl = ini_get('max_execution_time');
        $this->session_ttl = ini_get('session.gc_maxlifetime');
        $this->open_sessions = [];
        $this->new_sessions = [];
        $this->redis = new \Redis();
    }

    /**
     * {@inheritdoc}
     */
    public function open($save_path, $name)
    {
        return $this->redis->connect('redis');
    }

    /**
     * {@inheritdoc}
     */
    public function create_sid()
    {
        $id = parent::create_sid();

        $this->new_sessions[] = $id;

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function read($session_id)
    {
        $this->acquireLockOn($session_id);

        if ($this->mustRegenerate($session_id)) {
            session_regenerate_id(true);

            $session_id = session_id();
        }

        if (false === $session_data = $this->redis->get($session_id)) {
            $session_data = '';
        }

        return $session_data;
    }

    /**
     * {@inheritdoc}
     */
    public function write($session_id, $session_data)
    {
        return true === $this->redis->setex($session_id, $this->session_ttl, $session_data);
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($session_id)
    {
        $this->redis->del($session_id);
        $this->redis->del("{$session_id}_lock");

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->releaseLocks();

        $this->redis->close();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($maxlifetime)
    {
        // Redis does not need garbage collection, the builtin
        // TTL mechanism already takes care of stale sessions

        return true;
    }

    /**
     * @param string $session_id
     */
    private function acquireLockOn($session_id)
    {
        while (false === $this->redis->set("{$session_id}_lock", '', ['nx', 'ex' => $this->lock_ttl]));

        $this->open_sessions[] = $session_id;
    }

    private function releaseLocks()
    {
        foreach ($this->open_sessions as $session_id) {
            $this->redis->del("{$session_id}_lock");
        }

        $this->open_sessions = [];
    }

    /**
     * A session ID must be regenerated when it came from the HTTP
     * request and can not be found in Redis.
     *
     * When that happens it means that an old session ID expired
     * or a malicious client is trying to pull of a session fixation attack.
     *
     * @param string $session_id
     *
     * @return bool
     */
    private function mustRegenerate($session_id)
    {
        return false === in_array($session_id, $this->new_sessions)
            && false === $this->redis->exists($session_id);
    }
}
