<?php

namespace Ngos\AdminCore\Tests\Fixtures;

/** A string-backed status enum — exactly what `admin-core:make … status:enum` generates. */
enum EnumWidgetStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Posted = 'posted';
}
