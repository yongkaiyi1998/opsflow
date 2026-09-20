<?php

namespace App;

enum VendorMatchClassification: string
{
    case Likely = 'LIKELY';
    case Possible = 'POSSIBLE';
}
