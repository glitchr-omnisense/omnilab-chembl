# omnilab/chembl

ChEMBL for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a bioactive molecule by its
ChEMBL id, its InChIKey or its name; its type, its maximum development phase, its computed
properties (molecular weight, ALogP, polar surface area, hydrogen bond donors and acceptors,
Lipinski's rule of five, QED), its structure (SMILES, InChI, InChIKey, an SVG depiction), its
mechanisms of action and their targets, and its identifiers in ChEBI, PubChem, UNII and DSSTox,
which ChEMBL keeps in UniChem, for the Collector to follow; a search by text or InChIKey. Through
the ChEMBL data web services, no key.

```php
$chembl = (new ChemblSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$caffeine = $chembl->entity('CHEMBL113');
$caffeine->kind;                                 // small molecule
$caffeine->value('max_phase');                   // 4.0 (approved; first approval 1948)
$caffeine->property('alogp');                    // -1.03
$caffeine->property('tpsa');                     // 61.82 Å²
$caffeine->value('rule_of_five');                // true: no violation of Lipinski's rule of five
$caffeine->value('target');                      // Adenosine receptor (protein family, Homo sapiens)
$caffeine->related('target_protein');            // uniprot:P30542, P29275, P0DMS8, P29274: what it acts on
$caffeine->identifier(Scheme::CHEBI);            // 27732, from UniChem; PubChem 2519 too
$chembl->entity('RYYVLZVUVIJVGH-UHFFFAOYSA-N');  // the same, by its InChIKey; or 'caffeine', 'guaranine'
$chembl->search(new Query('caffeine', limit: 10));
$chembl->search(new Query('RYYVLZVUVIJVGH'));    // every molecule of that skeleton
```

```yaml
omnilab:
    sources:
        chembl:
            factory: chembl
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent: whom the EBI writes to before it blocks a caller
                synonyms: 20                   # how many to keep; 0: none
                unichem: true                  # ChEBI, PubChem, UNII and DSSTox ids from UniChem: one call
                mechanisms: true               # mechanisms of action and their targets: one or two calls
```

With PubChem configured too, the `Collector` follows a compound from one to the other:
`$collector->collect('pubchem', 'caffeine')` reaches ChEMBL by caffeine's InChIKey.

## Terms of use

ChEMBL is run by EMBL-EBI, free and without a key. Its data is under
[CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/) - cite ChEMBL, and share what you
derive from it alike - and access is governed by
[EMBL-EBI's terms of use](https://www.ebi.ac.uk/about/terms-of-use), which reserve the right to
block a client that loads the services unreasonably. No rate is published for the ChEMBL web
services: the source makes its calls in series, up to four for a molecule; cache what it answers.

See [docs/](docs/index.md) for what is read, how it maps, and what ChEMBL does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by EMBL-EBI (ChEMBL). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
