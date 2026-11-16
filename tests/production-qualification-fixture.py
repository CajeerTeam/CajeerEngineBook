"""Synthetic shape for tests of signing/validation only; never production evidence."""
def qualification_fixture(version,commit,images):
    return {'status':'lab-qualified','version':version,'commit':commit,'candidate_images':images,
            **{k:'a'*64 for k in ('source_digest','report_sha256','lifecycle_sha256','soak_sha256','candidate_lifecycle_sha256')},
            'soak_seconds':86400,'soak_requests':1728000,'minute_windows':1440,
            'rpo_seconds':1,'rto_seconds':2,'candidate_rpo_seconds':1,'candidate_rto_seconds':2}
