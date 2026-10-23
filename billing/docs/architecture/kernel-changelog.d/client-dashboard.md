### Dashboard registry: hidden widgets, `notice` type, list dates (#PR)

- A widget resolver may return `null`: the widget is left out of `resolve()` and `resolveWidgets()` for that request.
  Additive: resolvers that always return an array are unaffected.
- New widget type `notice` (a banner): `['title', 'body'?, 'tone' => 'warning'|'danger'|'info', 'actions' => [['label',
  'href', 'primary'?]]]`.
- `list` items may add `date` + `dateLabel`, and a list may add `emptyAction` => `['label', 'href']`.
