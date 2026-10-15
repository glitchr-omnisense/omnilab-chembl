<?php

namespace Omnilab\Chembl;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * ChEMBL: bioactive molecules, their development phase, computed
 * properties, mechanisms and targets, and their identifiers in other bases
 * (through UniChem) - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom the EBI writes to before it blocks a caller
 *     synonyms: 20                     # how many synonyms to keep (0: none)
 *     unichem: true                    # the identifiers of ChEBI, PubChem, UNII, DSSTox from UniChem (one call)
 *     mechanisms: true                 # the mechanisms of action and their targets (one or two calls)
 *     throttle: 0                      # seconds between two calls: the EBI publishes no figure for ChEMBL
 *     base_uri: 'https://www.ebi.ac.uk/chembl/api/data/'
 *     unichem_uri: 'https://www.ebi.ac.uk/unichem/api/v1/compounds'
 */
final class ChemblSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'chembl',
            'omnilab.required_options' => [],
            'synonyms' => 20,
            'unichem' => true,
            'mechanisms' => true,
            'throttle' => 0.0,
            'base_uri' => ChemblSource::BASE_URI,
            'unichem_uri' => ChemblSource::UNICHEM_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new ChemblSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('synonyms'),
            (bool) $c->get('unichem'),
            (bool) $c->get('mechanisms'),
            (string) $c->get('base_uri', ChemblSource::BASE_URI),
            (string) $c->get('unichem_uri', ChemblSource::UNICHEM_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
