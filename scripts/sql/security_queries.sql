-- Top IPs in the last 15 minutes
select ip, count(*) as total
from security_events
where ts >= now() - interval '15 minutes'
  and ip is not null
group by ip
order by total desc
limit 10;

-- Failed logins per IP in the last 15 minutes
select ip, count(*) as failed_logins
from security_events
where ts >= now() - interval '15 minutes'
  and event_type = 'auth_login_failed'
  and ip is not null
group by ip
order by failed_logins desc
limit 10;

-- 404 spike by one-minute window in the last 15 minutes
select date_trunc('minute', ts) as minute, count(*) as count_404
from security_events
where ts >= now() - interval '15 minutes'
  and status = 404
group by date_trunc('minute', ts)
order by minute desc;
