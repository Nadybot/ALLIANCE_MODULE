# Alliance module for Nadybot

This module allows you to manage membership to a bot via membership to one or more orgs.

***Note:** This module is still in early development, so things might change a bit over time until I'm 100% satisfied with it. Feedback is more than welcome*

You can dynamically add or remove orgs to and from the alliance and they will automatically be treated as if they were of access level "guild", so every org member can join the bot, receive mass messages and generally use the bot like a members.

Keep in mind that **all** members of **all orgs** will be added to the bot's friendly in order to allow mass invites/online lists, etc., so make sure you have a friendlist that's big enough.

## Help

```text
To list all orgs in your alliance, use
    !alliance list

To search and add an org:
    !alliance add 'name'

To add an org using its id:
    !alliance add 'orgid'

To remove an org using its id:
    !alliance rem 'orgid'

To update the roster for all orgs in your alliance:
    !alliance update
```
