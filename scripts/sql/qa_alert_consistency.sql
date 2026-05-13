-- 1) Events vs alerts vs responses per minute (last 15m)
select date_trunc('minute', ts) as minute, count(*) as events
from security_events
where ts >= now() - interval '15 minutes'
group by 1
order by 1;

select date_trunc('minute', detected_at) as minute, count(*) as alerts
from security_alerts
where detected_at >= now() - interval '15 minutes'
group by 1
order by 1;

select date_trunc('minute', created_at_event) as minute, count(*) as responses
from security_responses
where created_at_event >= now() - interval '15 minutes'
group by 1
order by 1;

-- 2) Alerts grouped by (type, detector)
select alert_type, detector_name, detector_version, count(*) as total
from security_alerts
group by 1,2,3
order by total desc;

-- 3) Dedup check
select count(*) as total_rows, count(distinct alert_id) as unique_alert_ids
from security_alerts;

-- 4) Average alerts per actor per window
with agg as (
  select actor_key, window_start, window_end, count(*) as c
  from security_alerts
  where actor_key is not null and actor_key <> ''
  group by 1,2,3
)
select avg(c)::numeric(10,4) as avg_alerts_per_actor_window from agg;

-- 5) Top actors by alert volume
select actor_key, count(*) as total
from security_alerts
where actor_key is not null and actor_key <> ''
group by 1
order by total desc
limit 20;
