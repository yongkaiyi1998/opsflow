<?php

namespace App;

enum WorkflowVersionStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Archived = 'ARCHIVED';
}
