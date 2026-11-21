import threading
import unittest

from face_inference.ratelimit import Decision, RateLimiter, client_ip


class RateLimiterTests(unittest.TestCase):
    def test_burst(self):
        limiter = RateLimiter(limit=5, window=60.0)
        for _ in range(5):
            decision = limiter.check('client-1')
            self.assertTrue(decision.allowed)
            self.assertEqual(decision.retry_after, 0)
            self.assertIsInstance(decision, Decision)

        # 6th request from client-1 should exceed limit
        decision = limiter.check('client-1')
        self.assertFalse(decision.allowed)
        self.assertGreater(decision.retry_after, 0)

        # Different client should have their own burst capacity
        decision_other = limiter.check('client-2')
        self.assertTrue(decision_other.allowed)
        self.assertEqual(decision_other.retry_after, 0)

    def test_window_rollover_with_fake_clock(self):
        current_time = 1000.0

        def fake_clock():
            return current_time

        limiter = RateLimiter(limit=3, window=60.0, clock=fake_clock)

        # Exhaust burst at t = 1000.0
        for _ in range(3):
            d = limiter.check('client-a')
            self.assertTrue(d.allowed)
            self.assertEqual(d.retry_after, 0)

        # Next check is denied
        d = limiter.check('client-a')
        self.assertFalse(d.allowed)
        self.assertGreater(d.retry_after, 0)

        # Advance clock by 60 seconds (window rollover)
        current_time += 60.0

        # Burst should be fully replenished
        for _ in range(3):
            d = limiter.check('client-a')
            self.assertTrue(d.allowed)
            self.assertEqual(d.retry_after, 0)

        # Exhausted again
        d = limiter.check('client-a')
        self.assertFalse(d.allowed)
        self.assertGreater(d.retry_after, 0)

    def test_eviction_10001st_key_allowed_and_oldest_evicted(self):
        # Memory bounded by max_keys using LRU eviction:
        # 10001st key allowed and oldest evicted; never reject because table is full.
        limiter = RateLimiter(limit=1, window=60.0, max_keys=10000)

        # Fill table with 10000 keys and exhaust their 1 token
        for i in range(10000):
            d = limiter.check(f'user-{i}')
            self.assertTrue(d.allowed)

        # user-0 would be rejected right now if checked before eviction
        d_denied = limiter.check('user-0')
        self.assertFalse(d_denied.allowed)

        # user-0 is now most-recently accessed (MRU), user-1 is now LRU
        # Insert 10001st key 'user-10000'
        d_new = limiter.check('user-10000')
        self.assertTrue(d_new.allowed)

        # user-1 should have been evicted (it was the oldest/LRU)
        # Checking user-1 now should treat it as a new client and allow it
        d_reentered = limiter.check('user-1')
        self.assertTrue(d_reentered.allowed)

    def test_thread_safety_smoke(self):
        limiter = RateLimiter(limit=500, window=60.0, max_keys=500)
        errors = []

        def worker(thread_id):
            try:
                for i in range(100):
                    key = f'client-{thread_id}-{i % 5}'
                    d = limiter.check(key)
                    self.assertIsInstance(d.allowed, bool)
                    self.assertIsInstance(d.retry_after, int)
            except Exception as e:
                errors.append(e)

        threads = [threading.Thread(target=worker, args=(t,)) for t in range(10)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()

        self.assertEqual(errors, [])

    def test_zero_limit(self):
        limiter = RateLimiter(limit=0, window=30.0)
        d = limiter.check('any')
        self.assertFalse(d.allowed)
        self.assertEqual(d.retry_after, 30)

    def test_default_parameters(self):
        limiter = RateLimiter(limit=120)
        self.assertEqual(limiter.window, 60.0)
        self.assertEqual(limiter.max_keys, 10000)
        d = limiter.check('127.0.0.1')
        self.assertTrue(d.allowed)
        self.assertEqual(d.retry_after, 0)

    def test_invalid_parameters_raise(self):
        with self.assertRaises(ValueError):
            RateLimiter(limit=-1)
        with self.assertRaises(ValueError):
            RateLimiter(limit=10, window=0)
        with self.assertRaises(ValueError):
            RateLimiter(limit=10, window=-5.0)
        with self.assertRaises(ValueError):
            RateLimiter(limit=10, max_keys=0)


class ClientIpTests(unittest.TestCase):
    def test_peer_not_in_trusted_proxies_ignores_header(self):
        # Spoofed XFF from an untrusted peer ignored
        peer = '203.0.113.195'
        forwarded = '198.51.100.1, 10.0.0.1'
        trusted = ['10.0.0.0/8', '127.0.0.1']
        resolved = client_ip(peer, forwarded, trusted)
        self.assertEqual(resolved, '203.0.113.195')

    def test_peer_untrusted_without_trusted_proxies_list(self):
        peer = '192.0.2.1'
        forwarded = '10.0.0.1'
        self.assertEqual(client_ip(peer, forwarded), '192.0.2.1')
        self.assertEqual(client_ip(peer, forwarded, ()), '192.0.2.1')

    def test_multi_hop_xff_with_trusted_proxies(self):
        # peer is trusted reverse proxy 2 (10.0.0.2)
        # XFF: <spoofed>, <real_client>, <trusted_proxy_1>
        # Walk from right: 10.0.0.1 (trusted) -> 203.0.113.50 (untrusted) -> stop
        peer = '10.0.0.2'
        forwarded = '1.2.3.4, 203.0.113.50, 10.0.0.1'
        trusted = ['10.0.0.0/8']
        resolved = client_ip(peer, forwarded, trusted)
        self.assertEqual(resolved, '203.0.113.50')

    def test_whitespace_and_iterable_proxies(self):
        peer = '10.0.0.2'
        forwarded = '  203.0.113.50  ,  10.0.0.1  '
        # trusted_proxies as generator
        trusted_gen = (p for p in ['10.0.0.0/8'])
        self.assertEqual(client_ip(peer, forwarded, trusted_gen), '203.0.113.50')

    def test_multi_hop_all_trusted_falls_back_to_peer(self):
        peer = '10.0.0.2'
        forwarded = '10.0.0.1'
        trusted = ['10.0.0.0/8']
        self.assertEqual(client_ip(peer, forwarded, trusted), '10.0.0.2')

    def test_ipv6_normalization_and_resolution(self):
        # Peer IPv6 normalization
        unnormalized_peer = '2001:0db8:0000:0000:0000:0000:0000:0001'
        self.assertEqual(client_ip(unnormalized_peer, None), '2001:db8::1')
        self.assertEqual(client_ip('::0001', None), '::1')

        # Forwarded IPv6 through trusted proxy
        peer = '127.0.0.1'
        forwarded = '2001:0db8:0000::0042, 10.0.0.1'
        trusted = ['127.0.0.1', '10.0.0.0/8']
        self.assertEqual(client_ip(peer, forwarded, trusted), '2001:db8::42')

        # IPv6 CIDR in trusted proxies
        peer = '2001:db8:1::1'
        forwarded = '198.51.100.5, 2001:db8:2::2'
        trusted = ['2001:db8::/32']
        self.assertEqual(client_ip(peer, forwarded, trusted), '198.51.100.5')

    def test_garbage_header_falls_back_to_peer(self):
        peer = '10.0.0.1'
        trusted = ['10.0.0.0/8']

        # Non-IP garbage
        self.assertEqual(client_ip(peer, 'not-an-ip', trusted), '10.0.0.1')
        self.assertEqual(client_ip(peer, 'for=1.2.3.4', trusted), '10.0.0.1')
        self.assertEqual(client_ip(peer, '1.2.3.4, garbage', trusted), '10.0.0.1')
        self.assertEqual(client_ip(peer, 'garbage, 1.2.3.4', trusted), '10.0.0.1')
        self.assertEqual(client_ip(peer, '', trusted), '10.0.0.1')
        self.assertEqual(client_ip(peer, '   ', trusted), '10.0.0.1')
        self.assertEqual(client_ip(peer, ',,', trusted), '10.0.0.1')

    def test_never_raises(self):
        # Odd types, malformed peer, None, etc.
        self.assertEqual(client_ip('invalid-peer', None), 'invalid-peer')
        self.assertEqual(client_ip('invalid-peer', '1.2.3.4', ['10.0.0.1']), 'invalid-peer')
        self.assertEqual(client_ip('127.0.0.1', None, None), '127.0.0.1')
        self.assertEqual(client_ip('127.0.0.1', 12345, ['127.0.0.1']), '127.0.0.1')  # type: ignore
