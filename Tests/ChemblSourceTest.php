<?php

namespace Omnilab\Chembl\Tests;

use Omnilab\Chembl\ChemblSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.ebi.ac.uk/chembl/api/data (ChEMBL 37) and
 * www.ebi.ac.uk/unichem/api/v1 on 2026-10-15: caffeine (CHEMBL113) and
 * trastuzumab (CHEMBL1201585, an antibody: no structure), their mechanisms
 * and targets, UniChem's sources for both (caffeine's 177 clinical trials
 * cut to 2; trastuzumab "Not found"), the lookups by name and by synonym
 * ("caffeine", "guaranine", an unknown name), an unknown id (404, empty
 * body), the search for "caffeine" (offsets 0 and 3, three a page) and the
 * searches by InChIKey and by its first block.
 */
final class ChemblSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $request) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            if ('POST' === $method) {
                $body = json_decode($request['body'], true);
                $this->calls[] = 'unichem '.$body['compound'];
                $fixture = 'unichem-'.$body['compound'].'.json';

                return $response ?? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
            }
            $this->calls[] = substr($path, \strlen('/chembl/api/data/')).($query ? '?'.http_build_query($query) : '');
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                (bool) preg_match('~/molecule/(CHEMBL113|RYYVLZVUVIJVGH-UHFFFAOYSA-N)\.json$~', $path) => 'molecule-CHEMBL113.json',
                str_ends_with($path, '/molecule/CHEMBL1201585.json') => 'molecule-CHEMBL1201585.json',
                str_ends_with($path, '/mechanism.json') => 'mechanism-'.$query['molecule_chembl_id'].'.json',
                str_ends_with($path, '/target/set/CHEMBL2111329.json') => 'target-set-CHEMBL2111329.json',
                str_ends_with($path, '/target/set/CHEMBL1824.json') => 'target-set-CHEMBL1824.json',
                isset($query['pref_name__iexact']) => match (strtolower($query['pref_name__iexact'])) {
                    'caffeine' => 'name-caffeine.json',
                    'guaranine' => 'name-guaranine.json',
                    default => 'name-notfound.json',
                },
                isset($query['molecule_synonyms__molecule_synonym__iexact']) => 'guaranine' === strtolower($query['molecule_synonyms__molecule_synonym__iexact']) ? 'synonym-guaranine.json' : 'synonym-notfound.json',
                str_ends_with($path, '/molecule/search.json') => 'search-caffeine-'.($query['offset'] ?? 0).'.json',
                isset($query['molecule_structures__standard_inchi_key']) => 'search-inchikey.json',
                isset($query['molecule_structures__standard_inchi_key__startswith']) => 'search-inchikey-skeleton.json',
                default => null,
            };

            return $fixture
                ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture))
                : new MockResponse('', ['http_code' => 404]);
        });

        return (new ChemblSourceFactory($http))->create($options);
    }

    public function testCaffeineByItsChemblId(): void
    {
        $caffeine = $this->source()->entity('CHEMBL113');

        self::assertSame('chembl:CHEMBL113', $caffeine->id->key());
        self::assertSame('Caffeine', $caffeine->label, 'the synonym that spells the preferred name, not its capitals');
        self::assertSame(Domain::CHEMISTRY, $caffeine->domain);
        self::assertSame('small molecule', $caffeine->kind);
        self::assertSame('C8H10N4O2', $caffeine->value('molecular_formula'));
        self::assertEquals(new Quantity(194.19, 'g/mol'), $caffeine->value('molecular_weight'));
        self::assertSame(-1.03, $caffeine->value('alogp'));
        self::assertEquals(new Quantity(61.82, 'Å²'), $caffeine->value('tpsa'));
        self::assertSame([0, 6, 0, 14, 2], [$caffeine->value('h_bond_donors'), $caffeine->value('h_bond_acceptors'), $caffeine->value('rotatable_bonds'), $caffeine->value('heavy_atoms'), $caffeine->value('aromatic_rings')]);
        self::assertSame(0, $caffeine->value('ro5_violations'));
        self::assertTrue($caffeine->value('rule_of_five'));
        self::assertFalse($caffeine->value('rule_of_three'));
        self::assertSame(0.54, $caffeine->value('qed'));
        self::assertSame(4.0, $caffeine->value('max_phase'));
        self::assertSame(1948, $caffeine->value('first_approval'));
        self::assertSame(['N06BC01', 'D11AX26'], array_map('strval', $caffeine->properties('atc_code')));
        self::assertTrue($caffeine->value('natural_product'));
        self::assertFalse($caffeine->value('withdrawn'));
        self::assertNull($caffeine->property('parent_chembl_id'), 'its own parent');
        self::assertSame('Cn1c(=O)c2c(ncn2C)n(C)c1=O', $caffeine->value('smiles'));
        self::assertSame('RYYVLZVUVIJVGH-UHFFFAOYSA-N', $caffeine->value('inchikey'));
        self::assertSame('chembl', $caffeine->property('molecular_weight')->source);
        self::assertSame('https://www.ebi.ac.uk/chembl/explore/compound/CHEMBL113', $caffeine->url);
        self::assertSame('https://www.ebi.ac.uk/chembl/api/data/image/CHEMBL113.svg', $caffeine->image);
        self::assertSame(['molecule/CHEMBL113.json', 'unichem CHEMBL113', 'mechanism.json?molecule_chembl_id=CHEMBL113&limit=50', 'target/set/CHEMBL2111329.json?only=target_chembl_id%2Cpref_name%2Ctarget_type%2Corganism'], $this->calls);
    }

    public function testItsMechanismAndTarget(): void
    {
        $caffeine = $this->source()->entity(Identifier::of(Scheme::CHEMBL, 'CHEMBL113'));

        self::assertSame('Adenosine receptor antagonist', $caffeine->value('mechanism_of_action'));
        self::assertSame('antagonist', $caffeine->value('action_type'));
        self::assertSame('Adenosine receptor (protein family, Homo sapiens)', $caffeine->value('target'));
        self::assertSame('CHEMBL2111329', $caffeine->value('target_chembl_id'));
    }

    public function testItsCrossReferencesFromUniChem(): void
    {
        $caffeine = $this->source()->entity('chembl:CHEMBL113');

        self::assertSame(['chembl:CHEMBL113', 'inchikey:RYYVLZVUVIJVGH-UHFFFAOYSA-N', 'inchi:InChI=1S/C8H10N4O2/c1-10-4-9-6-5(10)7(13)12(3)8(14)11(6)2/h4H,1-3H3', 'chebi:27732', 'pubchem.cid:2519', 'unii:3G6A5W338E', 'dsstox:DTXSID0020232'], $caffeine->identifiers()->keys());
        self::assertSame('2519', $caffeine->identifier(Scheme::PUBCHEM_CID));
    }

    public function testSynonymsWithoutBrandNamesNorCodes(): void
    {
        $caffeine = $this->source()->entity('CHEMBL113');

        self::assertSame(['Anhydrous caffeine', 'Caffeine anhydrous', 'Caffeine melting point standard', 'Caffeinum', 'Coffeinum', 'Durvitan', 'Guaranine', 'Synalgos', 'Vivarin'], $caffeine->synonyms);
        self::assertSame([], $this->source(null, ['synonyms' => 0])->entity('CHEMBL113')->synonyms);
    }

    public function testUniChemAndMechanismsCanBeLeftOut(): void
    {
        $caffeine = $this->source(null, ['unichem' => false, 'mechanisms' => false])->entity('CHEMBL113');

        self::assertSame(['molecule/CHEMBL113.json'], $this->calls);
        self::assertNull($caffeine->identifier(Scheme::CHEBI));
        self::assertNull($caffeine->property('target'));
    }

    public function testByInchikeyInTheSameCall(): void
    {
        $caffeine = $this->source(null, ['unichem' => false, 'mechanisms' => false])->entity('RYYVLZVUVIJVGH-UHFFFAOYSA-N');

        self::assertSame('chembl:CHEMBL113', $caffeine?->id->key());
        self::assertSame(['molecule/RYYVLZVUVIJVGH-UHFFFAOYSA-N.json'], $this->calls);
        self::assertSame('chembl:CHEMBL113', $this->source(null, ['unichem' => false, 'mechanisms' => false])->entity(Identifier::inchikey('RYYVLZVUVIJVGH-UHFFFAOYSA-N'))?->id->key());
    }

    public function testByNameThenBySynonym(): void
    {
        $source = $this->source(null, ['unichem' => false, 'mechanisms' => false]);
        self::assertSame('chembl:CHEMBL113', $source->entity('caffeine')?->id->key());
        self::assertSame(['molecule.json?pref_name__iexact=caffeine&limit=5'], $this->calls);

        $this->calls = [];
        self::assertSame('chembl:CHEMBL113', $source->entity('Guaranine')?->id->key());
        self::assertSame(['molecule.json?pref_name__iexact=Guaranine&limit=5', 'molecule.json?molecule_synonyms__molecule_synonym__iexact=Guaranine&limit=5'], $this->calls);
    }

    public function testAnAntibodyHasNoStructure(): void
    {
        $antibody = $this->source()->entity('CHEMBL1201585');

        self::assertSame('Trastuzumab', $antibody->label);
        self::assertSame('antibody', $antibody->kind);
        self::assertNull($antibody->image);
        self::assertNull($antibody->value('molecular_weight'));
        self::assertNull($antibody->identifier(Scheme::INCHIKEY));
        self::assertSame(4.0, $antibody->value('max_phase'));
        self::assertSame('Receptor tyrosine-protein kinase erbB-2 (single protein, Homo sapiens)', $antibody->value('target'));
        self::assertSame('inhibitor', $antibody->value('action_type'));
        self::assertSame(['chembl:CHEMBL1201585'], $antibody->identifiers()->keys(), 'UniChem knows no structure: "Not found"');
        self::assertNotContains('ABP-980', $antibody->synonyms, 'a research code');
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('CHEMBL999999999'));
        self::assertNull($this->source()->entity('nonexistentcompoundxyz'));
        self::assertNull($this->source()->entity(''));
    }

    public function testAnIdentifierChemblDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::cid(2519), Identifier::chebi(27732), Identifier::wikidata('Q60235'), '58-08-2', 'CHEBI:27732', Identifier::of(Scheme::UNIPROT, 'P29274')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchPageByPage(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('caffeine', limit: 3));

        self::assertSame(['chembl:CHEMBL113', 'chembl:CHEMBL1200569', 'chembl:CHEMBL1927665'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame(['Caffeine', 'Caffeine citrate', 'CHEMBL1927665'], array_map(static fn ($e) => $e->label, $page->items), 'a molecule without a name goes by its id');
        self::assertSame(110, $page->total);
        self::assertSame('3', $page->next);
        self::assertSame('CHEMBL113', $page->items[1]->value('parent_chembl_id'), 'the citrate is a salt of caffeine');

        $second = $source->search(new Query('caffeine', limit: 3, cursor: $page->next));
        self::assertSame(['chembl:CHEMBL483243', 'chembl:CHEMBL1927667', 'chembl:CHEMBL4587435'], array_map(static fn ($e) => $e->id->key(), $second->items));
        self::assertSame('6', $second->next);
        self::assertSame(['molecule/search.json?q=caffeine&limit=3', 'molecule/search.json?q=caffeine&limit=3&offset=3'], $this->calls, 'no cross-references nor mechanisms in a search');

        self::assertSame([], $source->search(new Query('caffeine', Domain::ASTRONOMY))->items, 'nothing of another domain');
        self::assertCount(2, $this->calls, 'and no call for it');
    }

    public function testSearchByInchikeyOrItsSkeleton(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('RYYVLZVUVIJVGH-UHFFFAOYSA-N'));
        self::assertSame(['chembl:CHEMBL113'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertTrue($page->isLast());

        $skeleton = $source->search(new Query('RYYVLZVUVIJVGH', limit: 3));
        self::assertSame('chembl:CHEMBL113', $skeleton->first()?->id->key());
        self::assertSame(['molecule.json?molecule_structures__standard_inchi_key=RYYVLZVUVIJVGH-UHFFFAOYSA-N&limit=25', 'molecule.json?molecule_structures__standard_inchi_key__startswith=RYYVLZVUVIJVGH-&limit=3'], $this->calls);
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]))->entity('CHEMBL113');
            self::fail('a 502 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(502, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('CHEMBL113');
    }

    public function testUniChemErrorsMapTheSameWay(): void
    {
        $http = new MockHttpClient(static fn (string $method): MockResponse => 'POST' === $method
            ? new MockResponse('', ['http_code' => 503])
            : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/molecule-CHEMBL113.json')));
        $this->expectException(UnavailableException::class);
        (new ChemblSourceFactory($http))->create(['mechanisms' => false])->entity('CHEMBL113');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
