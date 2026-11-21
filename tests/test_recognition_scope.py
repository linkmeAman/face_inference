import uuid

import pytest
import test_http

from face_inference.config import contract


@pytest.fixture(name='http')
def http_fixture():
    client = test_http.HttpTests()
    client.setUp()
    yield client
    client.tearDown()


def test_recognition_session_subject(http):
    def post(path, data, token=None):
        headers = {'Authorization': 'Bearer ' + token} if token else None
        return http.request(path, data, headers=headers)

    def setup(path, data):
        status, result = post(path, {**data, 'idempotency_key': str(uuid.uuid4())})
        assert status == 200, result

    setup('/api/v1/collections', {'id': 'team', 'name': 'Team'})
    model = contract()
    for index, person in enumerate(('a', 'b')):
        setup('/api/v1/collections/team/people', {'id': person, 'name': person})
        vector = [float(i == index) for i in range(128)]
        setup('/api/v1/collections/team/enrollments', {
            'person_id': person, 'consent': True, 'observations': [vector] * 5,
            'template': vector, 'capture_session': str(uuid.uuid4()),
            'model_version': model['model_version'],
            'preprocessing_version': model['preprocessing_version'],
        })

    def mint(**extra):
        status, result = post('/api/v1/session-tokens',
            {'collection_id': 'team', 'scope': 'recognize', **extra})
        assert status == 200, result
        return result['data']['token']

    scoped = mint(person_id='a')
    unrestricted = mint()
    path = '/api/v1/collections/team/recognitions'

    def recognize(index, token, **extra):
        vector = [float(i == index) for i in range(128)]
        return post(path, {'observations': [vector] * 5, 'capture_session': str(uuid.uuid4()),
            'idempotency_key': str(uuid.uuid4()), 'model_version': model['model_version'],
            'preprocessing_version': model['preprocessing_version'], **extra}, token)

    for eligibility in ({}, {'eligible_ids': ['a']}):
        status, result = recognize(0, scoped, **eligibility)
        assert (status, result['data']['person_id']) == (200, 'a')
        status, result = recognize(1, scoped, **eligibility)
        assert (status, result['data']['person_id']) == (200, None)
    for eligible in ([], ['b'], ['a', 'b'], None, 'a', [1], {'id': 'a'}):
        status, result = recognize(1, scoped, eligible_ids=eligible)
        assert (status, result['code'], result['message']) == (
            403, 'TOKEN_SCOPE', 'Session token is not permitted')
    for token in (unrestricted, None):
        status, result = recognize(1, token)
        assert (status, result['data']['person_id']) == (200, 'b')
        status, result = recognize(0, token, eligible_ids=['a'])
        assert (status, result['data']['person_id']) == (200, 'a')


def test_scoped_body_is_bound_before_mutation():
    client = test_http.SessionTokenTests()
    client.setUp()
    try:
        client.service.mutate = lambda collection, action, body: body
        status, result = client.request('/api/v1/session-tokens',
            {'collection_id': 'team', 'scope': 'recognize', 'person_id': 'a'})
        assert status == 200
        token = result['data']['token']
        path = '/api/v1/collections/team/recognitions'
        assert client.request(path, {}, token)[1]['data']['eligible_ids'] == ['a']
        assert client.request(path, {'eligible_ids': ['b']}, token)[0] == 403
    finally:
        client.doCleanups()
