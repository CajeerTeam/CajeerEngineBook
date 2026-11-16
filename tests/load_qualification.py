"""Bounded open-loop HTTP qualification, using real benchmark callbacks.

Offered arrivals are independent of response time. No unbounded executor queue:
excess arrivals are counted as rejected work, never silently omitted from SLOs.
"""
import concurrent.futures as futures
from collections import Counter
import math
import http.client
import time

# Upper bounds (ms), exact to 1 ms through 1 second, then 10 ms.
MAX_MS = 120000


class Histogram:
    def __init__(self):
        self.bins = Counter()
        self.count = 0

    def add(self, ms):
        bound = min(MAX_MS+1, math.ceil(ms if ms <= 1000 else ms / 10) * (1 if ms <= 1000 else 10))
        self.bins[bound] += 1
        self.count += 1

    def quantile(self, q):
        if not self.count:
            return None
        rank = math.ceil(self.count * q)
        for bound, count in sorted(self.bins.items()):
            rank -= count
            if rank <= 0:
                return bound


class Operation:
    def __init__(self):
        self.offered = self.completed = self.dropped = self.errors = self.integrity = 0
        self.scheduler_dropped = self.capacity_dropped = 0
        self.status = Counter()
        self.latency = Histogram()
        self.service = Histogram()
        self.bytes = 0

    def record(self, result):
        status, size, latency, service, kind = result
        self.completed += 1
        self.status[str(status)] += 1
        self.bytes += size
        if kind:
            self.errors += 1
            self.integrity += kind == 'integrity'
        else:
            self.latency.add(latency)
            self.service.add(service)

    def report(self, p95, p99, error_budget, minimum):
        bad = self.errors + self.dropped
        p = self.latency.quantile(.95)
        q = self.latency.quantile(.99)
        return {'requests': self.offered, 'completed': self.completed, 'dropped': self.dropped,
                'successes': self.latency.count, 'errors': bad, 'integrity_errors': self.integrity,
                'scheduler_dropped': self.scheduler_dropped, 'capacity_dropped': self.capacity_dropped,
                'status_counts': dict(self.status), 'response_bytes': self.bytes,
                'error_fraction': bad / max(1, self.offered), 'p50_ms': self.latency.quantile(.5),
                'p95_ms': p, 'p99_ms': q, 'service_p95_ms': self.service.quantile(.95),
                'p95_limit_ms': p95, 'p99_limit_ms': p99, 'min_samples': minimum,
                'passed': self.offered == self.completed + self.dropped and self.integrity == 0
                and self.latency.count >= minimum and bad / max(1, self.offered) <= error_budget
                and p is not None and p <= p95 and q <= p99}


def run_phase(name, operations, seconds, rate, concurrency, pids, resource_probe, limits,
              p99, error_budget, minimum, rss_growth, *, ramp_from=None, overload=False,
              start_index=0, sample_seconds=1, request_failure=RuntimeError, progress=None, window_seconds=60):
    """operations is a weighted list of (name, callable); every offered arrival counted.

    Integrity failures (wrong successful response, unexpected status) always fail,
    including overload. 429/503 and transport errors count against availability.
    """
    groups = {op: Operation() for op, _ in operations}
    start = time.monotonic(); end = start + seconds
    initial = resource_probe(pids); samples = []
    peak = initial['rss_bytes']; pending = {}; index = 0; next_sample = start
    max_pending = 0; max_lag = 0.; next_arrival = start
    # A completed window is serialized and discarded, so 24h runs need bounded memory.
    windows = {}; window_reports = []; last_window = -1
    def window(w):
        if w not in windows: windows[w] = {op:Operation() for op in groups}
        return windows[w]
    def finish_windows(current, force=False):
        active = {w for _,w in pending.values()}
        for w in sorted(list(windows)):
            if not force and (w>=current or w in active):continue
            interval=min(window_seconds,seconds-w*window_seconds)
            values={op:g.report(limits[op],p99,error_budget,1) for op,g in windows.pop(w).items() if g.offered}
            # Full windows must meet the SLO, short trailing fragments are reported only.
            window_reports.append({'start_seconds':w*window_seconds,'seconds':interval,'operations':values,'enforced':interval>=window_seconds,'passed':all(v['passed'] for v in values.values())})
    def arrival(i):
        if ramp_from is None:return start+i/rate
        slope=(rate-ramp_from)/seconds
        return start+2*i/(ramp_from+math.sqrt(ramp_from*ramp_from+2*slope*i))
    offered_count=math.ceil(seconds*(rate if ramp_from is None else (ramp_from+rate)/2))
    def call(fn, i, scheduled):
        begun = time.monotonic(); status = size = 0; kind = None
        try:
            status, size = fn(i)
        except request_failure as exc:
            status = getattr(exc, 'status', 0); size = getattr(exc, 'size', 0)
            kind = 'availability' if status in (429, 502, 503, 504) else 'integrity'
        except (OSError, TimeoutError, http.client.HTTPException):
            kind = 'availability'
        except Exception:
            kind = 'integrity'
        finished = time.monotonic()
        return status, size, (finished-scheduled)*1000, (finished-begun)*1000, kind
    def collect():
        for f in list(pending):
            if f.done():
                op,w=pending.pop(f);result=f.result();groups[op].record(result);window(w)[op].record(result)
    def sample(now):
        nonlocal peak, next_sample
        r = resource_probe(pids); peak = max(peak, r['rss_bytes'])
        samples.append({'elapsed_seconds': round(now-start, 3), **r,
                        'offered': sum(g.offered for g in groups.values()),
                        'completed': sum(g.completed for g in groups.values()),
                        'dropped': sum(g.dropped for g in groups.values())})
        next_sample = now + sample_seconds
        if progress: progress(samples[-1])
    with futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
        while index < offered_count:
            now = time.monotonic()
            if now >= next_sample: sample(now)
            if now < next_arrival:
                time.sleep(min(next_arrival-now, .02)); continue
            collect()
            op, fn = operations[index % len(operations)]; g = groups[op]; g.offered += 1
            w=min(int((seconds-1e-9)//window_seconds),int(max(0,next_arrival-start)//window_seconds)); wg=window(w)[op];wg.offered+=1
            if w!=last_window:finish_windows(w);last_window=w
            lag = now-next_arrival; max_lag=max(max_lag,lag)
            # Do not catch up a stalled generator with a burst of late requests.
            if len(pending) >= concurrency or lag > max(.05, 2/rate):
                for counter in (g,wg):
                    counter.dropped += 1
                    if lag > max(.05,2/rate):counter.scheduler_dropped+=1
                    else:counter.capacity_dropped+=1
            else:
                pending[pool.submit(call, fn, start_index+index, next_arrival)] = (op,w)
                max_pending=max(max_pending,len(pending))
            index += 1
            next_arrival=arrival(index)
        while pending:
            futures.wait(pending, timeout=.1, return_when=futures.FIRST_COMPLETED); collect()
            now=time.monotonic()
            if now>=next_sample: sample(now)
        while time.monotonic()<end:
            time.sleep(min(.1,max(0,end-time.monotonic())))
    finish_windows(last_window+1,True)
    sample(time.monotonic())
    elapsed=time.monotonic()-start; final=resource_probe(pids)
    # Compare a post-warmup baseline supplied by the caller through sequential phases;
    # within a phase a rising RSS is also visible in the samples.
    growth=max(0,final['rss_bytes']-initial['rss_bytes']);peak_growth=max(0,peak-initial['rss_bytes'])
    reports={op:g.report(limits[op],p99,error_budget,minimum) for op,g in groups.items()}
    integrity=sum(g.integrity for g in groups.values()); successes=sum(g.latency.count for g in groups.values())
    resources_ok=all(s['processes']==len(pids) for s in samples) and peak_growth<=rss_growth
    slo=all(x['passed'] for x in reports.values()) and all(w['passed'] for w in window_reports if w['enforced'])
    # Stress is exploratory; do not label a failed SLO as passed. Safety and recovery
    # are mandatory, and at least some real requests must have succeeded.
    passed=resources_ok and integrity==0 and (successes>0 if overload else slo)
    return {'phase':name,'requests':index,'concurrency':concurrency,'rate_limit_rps':rate,
            'ramp_from_rps':ramp_from,'offered_seconds':seconds,'seconds':round(elapsed,3),
            'throughput_rps':round(index/elapsed,3),'successful_rps':round(successes/elapsed,3),
            'max_inflight':max_pending,'max_scheduler_lag_ms':round(max_lag*1000,3),
            'operations':reports,'windows':window_reports,'slo_passed':slo,'exploratory_overload':overload,'passed':passed,
            'php_resources':{'peak_rss_bytes':peak,'rss_growth_bytes':growth,'peak_growth_bytes':peak_growth,'passed':resources_ok,
                             'initial':initial,'final':final,'samples':samples}}
