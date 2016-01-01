# MBDB

MBDB is a minimalist yet powerful PHP wrapper around mysqli.

License: https://opensource.org/licenses/MIT

## Main objectives & features

- easy to read keeping as close to SQL as possible
- provide simple SQL injection protection
- support nested transactions transparently
- simple inserts & updates using arrays, ie. ['a' => 1, 'b' => 2]
- queryOne()/queryFirst()/queryAll() semantic
- simple result iteration
- direct access to single column

## Whish list

- Plug-in architecture to inject query cache and timing functionalities.
- Simple SQL parser to analyze query's index usage.
