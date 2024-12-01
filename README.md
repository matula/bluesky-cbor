# Decode CBOR payload from Bluesky

```
// Using Rachet - MessageInterface $msg
$payload = $msg->getPayload();
$decoder = new \Matula\BlueskyCbor\NativeCborDecoder();
$data = $decoder->decode($payload);
```

this should return something like...

```
array:14 [
  "t" => "#commit"
  "op" => 1
  "ops" => array:1 [
    "array" => array:1 [
      0 => array:3 [
        "cid" => array:1 [
          "cid" => "00061fffffff16403"
        ]
        "path" => "app.bsky.feed.like/3lfffffff"
        "action" => "create"
      ]
    ]
  ]
  "rev" => "3lffffff1"
  "seq" => 927466666
  "prev" => null
  "repo" => "did:plc:11111111111"
  "time" => "2024-12-01T01:01:01.000Z"
  "blobs" => array:1 [▶]
  "since" => "3lffffff1"
  "blocks" => b":óeréñakX app.bsky.fee"
  "commit" => array:1 []
  "rebase" => false
  "tooBig" => false
]

```