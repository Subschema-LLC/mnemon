<?php

/**
 * Where the list of chat models is kept. The list is configuration, owned by
 * whoever runs Mnemon, so it lives in a settings file the admin panel and a
 * person can both edit, never in the database (FIDES rule 9).
 *
 * load() reads the list once per request, so one request never sees two
 * versions of it, except that after save() it returns the list just saved.
 * save() writes a whole list.
 */

namespace Mnemon\Entities\LlmModel;

interface LlmModelStore
{
    /** The saved list; empty when nothing has been saved yet. */
    public function load(): LlmModelList;

    public function save(LlmModelList $models): void;
}
