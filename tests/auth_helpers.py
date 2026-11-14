"""Real login for Playwright APIRequestContext and BrowserContext.request."""
import re

def login(api, base, password, username='admin'):
    form = api.get(base + '/login')
    assert form.status == 200, form.text()
    csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', form.text()).group(1)
    response = api.post(base + '/login', form={'csrf':csrf,'username':username,'password':password}, max_redirects=0)
    assert response.status == 303, response.text()
    return api
