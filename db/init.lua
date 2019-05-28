-- Создание хранилища пользовательских лент постов интересных авторов.
s=box.schema.space.create('user_feeds');
s:format({
    {name = 'id', type = 'unsigned'},
    {name = 'post_ids', type = 'array'}
})
s:create_index('primary')

-- Создание очереди обработки новых постов.
queue = require('queue')
queue.create_tube('new_post', 'fifottl', {if_not_exists=true})