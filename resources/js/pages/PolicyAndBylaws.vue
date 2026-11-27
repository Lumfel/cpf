<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BookOpen, Printer, ScrollText, Search } from '@lucide/vue';
import { computed, ref } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Policy & By-Laws',
                href: '/policy-and-bylaws',
            },
        ],
    },
});

interface SubItem {
    label: string;
    text: string;
}

interface SectionBlock {
    heading?: string;
    body?: string;
    paragraphs?: string[];
    items?: SubItem[];
}

interface ConstitutionArticle {
    id: string;
    article: string;
    title: string;
    sections: SectionBlock[];
}

const preamble = {
    id: 'preamble',
    article: 'PREAMBLE',
    text: 'We, the bonafide members of the Circle of Peer Facilitators, with the guidance of the Omnipotent God, is setting legal and non-political origin that shall embody our ideals to develop and enhance God-given capabilities, abilities and talents; and to promote the truth that, “A Person is of More Value Than the Whole World”, for we are committed to the goal of serving our fellow peers and students; and proclaim and make known in public this Constitution and By-Laws.',
};

const articles: ConstitutionArticle[] = [
    {
        id: 'article-i',
        article: 'ARTICLE I',
        title: 'NAME AND DOMICILE OF THE ORGANIZATION',
        sections: [
            {
                heading: 'Section 1:',
                body: 'This organization shall be known as the Circle of Peer Facilitators (CPF) of the Carlos Hilado Memorial State University (CHMSU), Alijis Campus, Bacolod City, Negros Occidental, Philippines.',
            },
            {
                heading: 'Section 2:',
                body: 'The CPF is a chapter organization of the Negros Occidental Peer Facilitators Guild (NOPFG) and of the Guidance Services Office of CHMSC, Alijis, Bacolod City.',
            },
        ],
    },
    {
        id: 'article-ii',
        article: 'ARTICLE II',
        title: 'OBJECTIVES',
        sections: [
            {
                heading: 'Section 1:',
                body: 'To uphold the truth that: “A person is of more value than the whole world.”',
            },
            {
                heading: 'Section 2:',
                body: 'To organize Peer Facilitators from different programs or course.',
            },
            {
                heading: 'Section 3:',
                body: 'To develop knowledge, skills and proper values on peer facilitating.',
            },
            {
                heading: 'Section 4:',
                body: 'To encourage harmonious relationships among Peer Facilitators and students.',
            },
            {
                heading: 'Section 5:',
                body: 'To develop volunteerism and render selfless service to Guidance Services and to peers within the school and the community through outreach program and the like.',
            },
        ],
    },
    {
        id: 'article-iii',
        article: 'ARTICLE III',
        title: 'JURISDICTION',
        sections: [
            {
                heading: 'Section 1:',
                body: 'The CPF has a jurisdiction over members who signifies his/her willingness to join this organization.',
            },
        ],
    },
    {
        id: 'article-iv',
        article: 'ARTICLE IV',
        title: 'CHAPTER PRINCIPLES AND POLICIES',
        sections: [
            {
                heading: 'Section 1:',
                body: 'The CPF is a legal entity, distinct and separate organization from other legally existing organizations.',
            },
            {
                heading: 'Section 2:',
                body: 'The CPF shall strengthen and promote harmonious relationships among the officers and members, and establish unity with all legally existing organization within the school.',
            },
            {
                heading: 'Section 3:',
                body: 'It shall be the primary obligation of the Circle of Peer Facilitators to uphold the University’s Guidance Program, and abide with the existing rules and regulations of the school.',
            },
            {
                heading: 'Section 4:',
                body: 'It shall be the obligation of the CPF to inform the members if the current development in the field of Counseling and Guidance.  In the fulfilment of such duty, the officers shall facilitate seminars, meetings, workshops, or in any manner tending to promote the Guidance and Peer Facilitating.',
            },
            {
                heading: 'Section 5:',
                body: 'The Circle of Peer Facilitators is not an ideological nor a political organization nor an instrument organization which tends to propagate political or ideological beliefs.',
            },
        ],
    },
    {
        id: 'article-v',
        article: 'ARTICLE V',
        title: 'NATURE OF MEMBERSHIP AND ORGANIZATION',
        sections: [
            {
                heading: 'Section 1: Composition',
                items: [
                    {
                        label: 'a.',
                        text: 'Any existing students of CHMSU-Alijis may become a member of CPF, provided that he/she signifies his/her intention and willingness to become such.',
                    },
                    {
                        label: 'b.',
                        text: 'Should undergo training on peer facilitating sensitivity.',
                    },
                ],
            },
            {
                heading: 'Section 2: Membership',
                body: 'All applicants shall fill up membership forms and pay membership fees prior to their induction and approval of their membership.',
            },
            {
                heading: 'Section 3: Qualification',
                items: [
                    { label: 'a.', text: 'He/She must be a bonafide student of CHMSU-Alijis;' },
                    { label: 'b.', text: 'He/She must have good academic standing;' },
                    { label: 'c.', text: 'He/She must have a good moral character;' },
                    { label: 'd.', text: 'Has not been convicted of any crime before court of justice.' },
                ],
            },
            {
                heading: 'Section 4: Bonafide Members',
                body: 'Those who upon completion of the requirements of the proceeding section and have been inducted into the organization in a ceremony.',
            },
            {
                heading: 'Section 5:',
                body: 'The term of all the members shall be one (1) year and renewable every year.',
            },
        ],
    },
    {
        id: 'article-vi',
        article: 'ARTICLE VI',
        title: 'RIGHTS, DUTIES AND OBLIGATIONS OF MEMBERS',
        sections: [
            {
                heading: 'Section 1:',
                body: 'The members shall have the right to be informed of all activities and undertakings of the CPF and likewise, shall have the right to access of all the records of the CPF provided, that such information shall not be used for illegal purposes.',
            },
            {
                heading: 'Section 2:',
                body: 'It shall be the primary of all members to be loyal to the CPF to uphold this Constitution and By-Laws, to obey all policies adopted, Rules and Regulations promulgated, memoranda, notices, circulars and other issues by the CPF to be cooperative and enthusiastically participate and contribute in gainful activities and undertakings sponsored by the CPF.',
            },
            {
                heading: 'Section 3:',
                body: 'It shall be the duty of all the members to respect and recognize all school officials, faculty, personnel, fellow students, peer members and other duly constituted authorities.',
            },
            {
                heading: 'Section 4:',
                body: 'To attend each general assembly, if sent as delegate.',
            },
        ],
    },
    {
        id: 'article-vii',
        article: 'ARTICLE VII',
        title: 'THE OFFICERS',
        sections: [
            {
                heading: 'Section 1: The President',
                items: [
                    { label: 'a.', text: 'Shall be the head of the organization.' },
                    { label: 'b.', text: 'Shall preside over all meetings of the organization.' },
                    { label: 'c.', text: 'Shall sigh all letters and written reports under the name of the organization.' },
                    { label: 'd.', text: 'Shall submit Accomplishment Report at the end of the term and prepare documents required for the renewal of the organization.' },
                    { label: 'e.', text: 'Shall coordinate with and pass through the Adviser’s approval all plans and undertakings of the organization.' },
                    { label: 'f.', text: 'Shall be of good moral character in and out of the campus.' },
                    { label: 'g.', text: 'Shall perform other duties as prescribed by law.' },
                ],
            },
            {
                heading: 'Section 2: Vice President',
                items: [
                    { label: 'a.', text: 'Shall take over the President’s position in case of absence or incapacity, permanent or not and do the duties and exercise the same power as that of the President.' },
                    { label: 'b.', text: 'Shall be of good moral character in and out of the campus.' },
                    { label: 'c.', text: 'Shall perform duties assigned by the President.' },
                ],
            },
            {
                heading: 'Section 3: Secretary',
                items: [
                    { label: 'a.', text: 'Shall maintain a correct record of all the meetings of the body.' },
                    { label: 'b.', text: 'Shall take charge of all the records of the organization.' },
                    { label: 'c.', text: 'Shall be responsible for making/typing all letters in connection with the organization’s activities, and all other related purposes.' },
                    { label: 'd.', text: 'Shall take down minutes of the meetings, print and disseminate to concerns.' },
                    { label: 'e.', text: 'Shall perform all other functions maybe assigned to her/him by the President pertaining to his/her position.' },
                    { label: 'f.', text: 'Shall turn over all the records and documents belonging to the organization to his/her successor during the turning over ceremony.' },
                    { label: 'g.', text: 'Shall be of good moral character in and out of the campus.' },
                    { label: 'h.', text: 'Shall perform other duties assigned by the President.' },
                ],
            },
            {
                heading: 'Section 4: Treasurer',
                items: [
                    { label: 'a.', text: 'Shall be responsible or shall take custody of all money and papers pertaining to financial matter of the organization.' },
                    { label: 'b.', text: 'Shall give a monthly report of the funds of the organization during monthly meeting.' },
                    { label: 'c.', text: 'Shall file all reports and complaints regarding money matters of the organization for future reference.' },
                    { label: 'd.', text: 'Shall be authorized to keep the funds of the organization in the bank, on the condition that the money should kept under three (3) signatures namely, that of the President, Treasurer, and Adviser.' },
                    { label: 'e.', text: 'Shall turn over all the money left, papers and other properties pertaining to financial matters belonging to the organization, to his/her successor during the turning over ceremony.' },
                    { label: 'f.', text: 'Shall submit Liquidation Report with original receipts, and Financial Report every end of the term.' },
                    { label: 'g.', text: 'Shall be of good moral character in or out of the campus.' },
                    { label: 'h.', text: 'Shall perform other duties assigned by the President.' },
                ],
            },
            {
                heading: 'Section 5. Auditor',
                items: [
                    { label: 'a.', text: 'Shall audit the incoming and outgoing fund; and shall record it for future use.' },
                    { label: 'b.', text: 'Shall audit and certify correct financial reports made by the Treasurer.' },
                    { label: 'c.', text: 'Shall report to the executive officers of any deficiency therein, and shall report to the organization any problems regarding financial activities of the organization which he thinks not being addressed properly the officers of the organization.' },
                    { label: 'd.', text: 'Shall be of good moral character in or out of the campus.' },
                ],
            },
            {
                heading: 'Section 6. Business Manager',
                items: [
                    { label: 'a.', text: 'Shall be in-charge off all business transactions that the organization has plan to undertake and shall be responsible of such duties pertaining to business which may come into this position.' },
                    { label: 'b.', text: 'Shall be of good moral character in and out of the school campus.' },
                    { label: 'c.', text: 'Shall perform other duties assigned by the President.' },
                ],
            },
            {
                heading: 'Section 7. The Public Information Officer',
                items: [
                    { label: 'a.', text: 'Shall take charge of disseminating the different information covering the affairs of the organization.' },
                    { label: 'b.', text: 'Shall inform the Officers and concerns of the scheduled meetings.' },
                    { label: 'c.', text: 'Shall be of good moral character in and out of the school campus.' },
                    { label: 'd.', text: 'Shall perform other duties assigned by the President.' },
                ],
            },
            {
                heading: 'Section 8. The Sergeant-At-Arms',
                items: [
                    { label: 'a.', text: 'Shall take charge of maintaining peace and order during meetings and activities.' },
                    { label: 'b.', text: 'Shall be of good moral character in and out of the school campus.' },
                    { label: 'c.', text: 'Shall perform other duties assigned by the President.' },
                ],
            },
        ],
    },
    {
        id: 'article-viii',
        article: 'ARTICLE VIII',
        title: 'THE ADVISER',
        sections: [
            {
                heading: 'Section 1.',
                body: 'The CPF must have at least one (1) Adviser to guide and to help the officers in the exercise of their duties and powers.',
            },
            {
                heading: 'Section 2.',
                body: 'Upon the election of the President, the Guidance Counselor of the school is automatically becomes the adviser of the organization.',
            },
        ],
    },
    {
        id: 'article-ix',
        article: 'ARTICLE IX',
        title: 'ELECTION OF OFFICERS AND TERM OF OFFICE',
        sections: [
            {
                heading: 'Section 1. Election of Officers',
                body: 'The election of the Officers shall be held annually within the month of July during the assembly of students who indicated their willingness to join the organization.',
            },
            {
                heading: 'Section 2. Eligibility for Candidacy',
                items: [
                    { label: 'a.', text: 'Must be a CPF member.' },
                    { label: 'b.', text: 'Must be enrolled in the current school year/semester.' },
                    { label: 'c.', text: 'Must have a good moral character duly certified by the Adviser.' },
                ],
            },
            {
                heading: 'Section 3. Rules governing elections shall be as follows:',
                items: [
                    { label: 'a.', text: 'The election of officers will be done in a viva-voce manner.' },
                    { label: 'b.', text: 'Nomination of candidates from among the students who are present during meeting/assembly called by the Guidance Office.' },
                ],
            },
            {
                heading: 'Section 4. Term of Office',
                items: [
                    { label: 'a.', text: 'Officers of the organization shall hold office for one (1) school year.' },
                    { label: 'b.', text: 'Officers must conform to their respective roles, functions and obligations as stated in Article VII.' },
                ],
            },
        ],
    },
    {
        id: 'article-xi',
        article: 'ARTICLE XI',
        title: 'VACANCY',
        sections: [
            {
                heading: 'Section 1.',
                body: 'In case of temporary or permanent vacancy, the President has the power to appoint any qualified member of the Organization with the approval of the Officers.',
            },
            {
                heading: 'Section 2.',
                body: 'In case of permanent vacancy, in the Office of the President, the Vice-President shall automatically be elevated and assume office.',
            },
        ],
    },
    {
        id: 'article-xii',
        article: 'ARTICLE XII',
        title: 'MEETINGS',
        sections: [
            {
                heading: 'Section 1.',
                body: 'The regular meeting of the CPF officers shall be held once a month at such date, time, and place as may be indicated in the notice.',
            },
            {
                heading: 'Section 2.',
                body: 'Special meetings, seminars, and the like, be held any time upon the call of the President whenever there is any urgency and subject to the approval of the adviser.',
            },
            {
                heading: 'Section 3.',
                body: 'The place of all the meetings of the CPF shall be held at such place indicated in the notice.',
            },
            {
                heading: 'Section 4.',
                body: 'To form a quorum, there should be a majority of the Officers present.  It is required in every meeting that there should be a quorum.  In case that there is no quorum, a caucus may be held to save wastage of time, money and effort.',
                items: [
                    { label: 'a.', text: 'In every meeting of the CPF officers, there should be the presence of the President, Vice-President, Secretary, Treasurer, Auditor, Business Manager, and PIO.  Other officers aside from the above-mentioned may attend the meeting.' },
                    { label: 'b.', text: 'The CPF Adviser or his/her representative must be present in all meetings.  The representative must be a Guidance personnel of the school.' },
                ],
            },
            {
                heading: 'Section 5. The Order of Meeting',
                items: [
                    { label: 'a.', text: 'Call to order by the presiding officer.' },
                    { label: 'b.', text: 'Reading of the minutes of the last meeting by the Secretary.' },
                    { label: 'c.', text: 'Discussion of the unapproved part of minutes.' },
                    { label: 'd.', text: 'Officers’ and Committees’ reports.' },
                    { label: 'e.', text: 'Reading and discussion of the present meeting’s agenda and other matters.' },
                    { label: 'f.', text: 'The adjournment.' },
                ],
            },
            {
                heading: 'Section 6. Rules and Orders',
                body: 'The rules of Parliamentary Procedures shall be adopted in accordance to Robert’s Rules and Orders, if necessary.',
            },
        ],
    },
    {
        id: 'article-xiii',
        article: 'ARTICLE XIII',
        title: 'FUNDS',
        sections: [
            {
                heading: 'Section 1.',
                body: 'The funds of CPF shall consist of the following: Registration Fees, Membership fees, Renewal Fees, Donations, Contributions, Organizational T-shirt Printing, and others as approved.',
            },
            {
                heading: 'Section 2. Membership Fee',
                body: 'Each member must pay the regular membership fee on the amount agreed upon by the officers of the CPF.',
            },
            {
                heading: 'Section 3. Renewal Fees',
                body: 'All members shall renew their membership every year upon payment of Renewal Fee of the amount agreed upon by the majority of the officers.',
            },
        ],
    },
    {
        id: 'article-xiv',
        article: 'ARTICLE XIV',
        title: 'GENERAL PROVISIONS',
        sections: [
            {
                heading: 'Section 1.',
                body: 'No member shall enter into a contract for and on behalf of the CPF without prior approval of the majority of all elected officers, and no approval shall be valid without the signature of the President, Vice-President and the Adviser.',
            },
            {
                heading: 'Section 2.',
                body: 'The organization shall have the power to create its own source of funds.',
            },
        ],
    },
    {
        id: 'article-xv',
        article: 'ARTICLE XV',
        title: 'DISCIPLINARY SANCTIONS',
        sections: [
            {
                heading: 'Section 1.',
                body: 'Any form of serious misdemeanour is subject to disciplinary sanction.',
            },
            {
                heading: 'Section 2.',
                body: 'Violation of Section 1 and 2 of Article XIV will subject one to disciplinary action.',
            },
            {
                heading: 'Section 3.',
                body: 'Such disciplinary action may be in the form of private or public confession or both, and apology to the CPF depending upon the seriousness of the offense.',
            },
            {
                heading: 'Section 4.',
                body: 'Penalty will be meted out by the Adviser of the CPF and in reference to the school’s disciplinary guidelines.',
            },
        ],
    },
    {
        id: 'article-xvi',
        article: 'ARTICLE XVI',
        title: 'AMENDMENTS',
        sections: [
            {
                heading: 'Section 1.',
                body: 'The Officers, by a majority vote, may propose amendments or repeal, in whole or in part, of this Constitution and By-Laws, provided that such amendments or repeal shall be valid only when approved by the affirmative vote of the majority of the members.',
            },
            {
                heading: 'Section 2.',
                body: 'This Constitution and By-Laws was amended on November 23, 2023 to update the institutional name from Carlos Hilado Memorial State College to Carlos Hilado Memorial State University, in compliance with the University Charter.',
            },
        ],
    },
    {
        id: 'article-xvii',
        article: 'ARTICLE XVII',
        title: 'EFFECTIVITY CLAUSE',
        sections: [
            {
                paragraphs: [
                    'Adoption of the Constitution and By-Laws shall take effect immediately after the ratification of the same by the CPF Officers and members during the General Assembly.',
                    'Done this 23rd day of November 2023.',
                ],
            },
        ],
    },
];

const searchQuery = ref('');

const showPreamble = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return true;
    return preamble.article.toLowerCase().includes(q) || preamble.text.toLowerCase().includes(q);
});

const filteredArticles = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return articles;

    return articles.filter((art) => {
        if (art.article.toLowerCase().includes(q) || art.title.toLowerCase().includes(q)) {
            return true;
        }
        return art.sections.some(
            (sec) =>
                (sec.heading ?? '').toLowerCase().includes(q) ||
                (sec.body ?? '').toLowerCase().includes(q) ||
                (sec.paragraphs ?? []).some((p) => p.toLowerCase().includes(q)) ||
                (sec.items ?? []).some(
                    (item) => item.label.toLowerCase().includes(q) || item.text.toLowerCase().includes(q),
                ),
        );
    });
});

function printDocument() {
    if (typeof window !== 'undefined') {
        window.print();
    }
}
</script>

<template>
    <Head title="Policy & By-Laws — CPF Constitution" />

    <div class="flex min-h-full w-full max-w-none flex-1 flex-col gap-3.5 bg-background p-3 text-foreground md:p-4">
        <!-- Header Card -->
        <section class="bento p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="cpf-logo-badge size-12 shrink-0 overflow-hidden rounded-full border border-primary/30 bg-white shadow-xs">
                        <img src="/cpf_logo.png" alt="Circle of Peer Facilitators Emblem" class="size-full object-cover" />
                    </div>
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary">
                            <ScrollText class="size-3.5" />
                            Official Governing Document • Amended November 23, 2023
                        </span>
                        <h1 class="font-editorial mt-1 text-xl font-bold text-foreground md:text-2xl">
                            Constitution and By-Laws — Circle of Peer Facilitators (CPF)
                        </h1>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Carlos Hilado Memorial State University (CHMSU) — Alijis Campus, Bacolod City, Negros Occidental
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 print:hidden">
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-muted-foreground" />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search articles, roles, sections..."
                            class="w-60 rounded-lg border border-border bg-card py-1.5 pr-3 pl-8 text-sm text-foreground focus:border-primary focus:outline-none"
                        />
                    </div>
                    <button
                        type="button"
                        @click="printDocument"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-primary/35 bg-primary/10 px-3 py-1.5 text-sm font-bold text-primary hover:bg-primary hover:text-primary-foreground"
                    >
                        <Printer class="size-3.5" />
                        Print / Save PDF
                    </button>
                </div>
            </div>

            <div class="mt-3 rounded-xl border border-[#FF8D06]/30 bg-background/80 px-3.5 py-2.5 text-center text-sm font-semibold text-foreground italic">
                “A Person is of More Value Than the Whole World”
            </div>
        </section>

        <!-- 2-Column Index & Full Constitution Text -->
        <div class="grid gap-3.5 lg:grid-cols-[240px_1fr]">
            <!-- Sticky Table of Contents -->
            <aside class="bento hidden h-fit p-3.5 lg:sticky lg:top-4 lg:block print:hidden">
                <div class="flex items-center gap-2 border-b border-border pb-2 text-xs font-bold text-foreground uppercase">
                    <BookOpen class="size-3.5 text-primary" />
                    Articles Index
                </div>
                <nav class="mt-2 flex max-h-[72vh] flex-col gap-0.5 overflow-y-auto pr-1 text-sm">
                    <a
                        href="#preamble"
                        class="rounded-lg px-2.5 py-1.5 font-semibold text-foreground hover:bg-primary/10 hover:text-primary"
                    >
                        Preamble
                    </a>
                    <a
                        v-for="art in articles"
                        :key="`toc-${art.id}`"
                        :href="`#${art.id}`"
                        class="rounded-lg px-2.5 py-1.5 text-foreground hover:bg-primary/10 hover:text-primary"
                    >
                        <span class="font-bold text-primary">{{ art.article }}</span>
                        <span class="block truncate text-xs text-muted-foreground">{{ art.title }}</span>
                    </a>
                </nav>
            </aside>

            <!-- Document Body -->
            <div class="flex flex-col gap-3.5">
                <!-- Preamble -->
                <article
                    v-if="showPreamble"
                    id="preamble"
                    class="bento scroll-mt-4 p-4"
                >
                    <h2 class="font-editorial text-base font-bold tracking-wide text-primary">
                        PREAMBLE
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-foreground">
                        {{ preamble.text }}
                    </p>
                </article>

                <!-- Articles I through XVII -->
                <div
                    v-if="!showPreamble && filteredArticles.length === 0"
                    class="bento p-6 text-center text-sm text-muted-foreground"
                >
                    No matching Constitution articles or sections found for “{{ searchQuery }}”.
                </div>

                <article
                    v-for="art in filteredArticles"
                    :key="art.id"
                    :id="art.id"
                    class="bento scroll-mt-4 p-4"
                >
                    <div class="border-b border-border pb-2.5">
                        <div class="text-xs font-extrabold tracking-widest text-primary uppercase">
                            {{ art.article }}
                        </div>
                        <h2 class="font-editorial mt-0.5 text-base font-bold text-foreground">
                            {{ art.title }}
                        </h2>
                    </div>

                    <div class="mt-3 space-y-3 text-sm leading-relaxed text-foreground">
                        <div
                            v-for="(sec, sIdx) in art.sections"
                            :key="`${art.id}-sec-${sIdx}`"
                            class="space-y-1.5"
                        >
                            <h3 v-if="sec.heading" class="font-bold text-foreground">
                                {{ sec.heading }}
                            </h3>
                            <p v-if="sec.body" class="text-muted-foreground">
                                {{ sec.body }}
                            </p>
                            <template v-if="sec.paragraphs">
                                <p
                                    v-for="(para, pIdx) in sec.paragraphs"
                                    :key="`${art.id}-p-${sIdx}-${pIdx}`"
                                    class="text-muted-foreground"
                                    :class="pIdx === sec.paragraphs.length - 1 && art.id === 'article-xvii' ? 'pt-2 font-bold text-foreground' : ''"
                                >
                                    {{ para }}
                                </p>
                            </template>
                            <ul v-if="sec.items" class="mt-1.5 space-y-1.5 pl-4 text-muted-foreground">
                                <li
                                    v-for="(item, iIdx) in sec.items"
                                    :key="`${art.id}-item-${sIdx}-${iIdx}`"
                                    class="flex gap-2"
                                >
                                    <span class="shrink-0 font-bold text-primary">{{ item.label }}</span>
                                    <span>{{ item.text }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </div>
</template>
